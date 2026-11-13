<?php
/**
 * Replays the audit log against a policy.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Decides each logged call again with Policy::resolve(), the function live calls
 * use, for any number of policy sets in one pass. The first set is the one to
 * compare the others with. Nothing is saved and no call is run.
 */
final class Simulator {

	/**
	 * What a call can come to, in the order the screens list them.
	 */
	public const OUTCOMES = array( 'allow', 'require_approval', 'deny', 'rate_limit' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The log.
	 *
	 * @var Log
	 */
	private Log $log;

	/**
	 * Sets up the simulator.
	 *
	 * @param Settings $settings Settings and rules.
	 * @param Log      $log      The audit log.
	 */
	public function __construct( Settings $settings, Log $log ) {
		$this->settings = $settings;
		$this->log      = $log;
	}

	/**
	 * Days of history a simulation reads.
	 *
	 * @param int $requested Days asked for, 0 for the default.
	 * @return int
	 */
	public static function days( int $requested = 0 ): int {
		/**
		 * Filters the days of history a policy simulation replays.
		 *
		 * @param int $days Default 30, or the number asked for. 1 to 365. The log
		 *                  only goes back as far as its retention setting.
		 */
		return max( 1, min( 365, (int) apply_filters( 'agent_governance_simulation_days', $requested > 0 ? $requested : 30 ) ) );
	}

	/**
	 * Most logged calls one simulation reads. The newest ones are kept.
	 *
	 * @return int
	 */
	public static function max_calls(): int {
		/**
		 * Filters the most calls one simulation replays.
		 *
		 * @param int $calls Default 20000. 100 to 200000.
		 */
		return max( 100, min( 200000, (int) apply_filters( 'agent_governance_simulation_max_calls', 20000 ) ) );
	}

	/**
	 * Example calls kept for each kind of change.
	 *
	 * @return int
	 */
	public static function examples_limit(): int {
		/**
		 * Filters how many example calls a simulation keeps for each agent and kind of change.
		 *
		 * @param int $examples Default 3. 0 to 10.
		 */
		return max( 0, min( 10, (int) apply_filters( 'agent_governance_simulation_examples', 3 ) ) );
	}

	/**
	 * Seconds one simulation may run before it stops and reports what it has.
	 *
	 * @return int
	 */
	public static function time_limit(): int {
		/**
		 * Filters the seconds a simulation may run before it stops with a partial result.
		 *
		 * @param int $seconds Default 20. 1 to 120.
		 */
		return max( 1, min( 120, (int) apply_filters( 'agent_governance_simulation_seconds', 20 ) ) );
	}

	/**
	 * The policy that is live today, in the shape resolve() takes.
	 *
	 * @return array{rules: array[], defaults: array, rate_limit: int, rate_limit_for: callable}
	 */
	public function live(): array {
		$settings = $this->settings;

		return array(
			'rules'          => $settings->all_rules(),
			'defaults'       => $settings->default_rules(),
			'rate_limit'     => $settings->rate_limit( '' ),
			'rate_limit_for' => static function ( string $identity ) use ( $settings ): int {
				return $settings->rate_limit( $identity );
			},
		);
	}

	/**
	 * A draft policy from submitted values. Anything left out keeps its live value,
	 * rules defined in code stay, and everything is cleaned the way saved settings are.
	 *
	 * @param array $input rules (list), defaults (action per kind) and rate_limit.
	 * @return array{rules: array[], defaults: array, rate_limit: int}
	 */
	public function draft( array $input ): array {
		$live  = $this->live();
		$rules = $live['rules'];

		if ( isset( $input['rules'] ) && is_array( $input['rules'] ) ) {
			$rules = array();
			foreach ( $input['rules'] as $rule ) {
				if ( is_array( $rule ) && empty( $rule['remove'] ) ) {
					$rule = Settings::sanitize_rule( $rule );
					if ( $rule ) {
						$rules[] = $rule;
					}
				}
			}

			// Rules defined in code are not on the form, and stay in the draft.
			$saved = array_column( $this->settings->rules(), 'id' );
			foreach ( $live['rules'] as $rule ) {
				if ( ! in_array( $rule['id'], $saved, true ) ) {
					$rules[] = $rule;
				}
			}
		}

		$clean = Settings::sanitize(
			array(
				'defaults'   => isset( $input['defaults'] ) && is_array( $input['defaults'] ) ? $input['defaults'] : array(),
				'rate_limit' => $input['rate_limit'] ?? $live['rate_limit'],
			)
		);

		// A default that was not given stays what it is today.
		$defaults = $live['defaults'];
		foreach ( Settings::KINDS as $kind ) {
			if ( isset( $input['defaults'][ $kind ] ) && in_array( $input['defaults'][ $kind ], Settings::ACTIONS, true ) ) {
				$defaults[ $kind ] = $clean['defaults'][ $kind ];
			}
		}

		return array(
			'rules'      => $rules,
			'defaults'   => $defaults,
			'rate_limit' => (int) $clean['rate_limit'],
		);
	}

	/**
	 * Replays the log.
	 *
	 * @param array<string, array> $policies Policy sets by name. The first one is the baseline.
	 * @param int                  $days     Days of history, 0 for the default.
	 * @return array See compare(), plus days, since, truncated (the cap cut the window short) and partial (the time limit stopped it).
	 */
	public function run( array $policies, int $days = 0 ): array {
		$days  = self::days( $days );
		$since = time() - $days * DAY_IN_SECONDS;
		$cap   = self::max_calls();
		$start = $this->log->replay_start( $since, $cap );
		$log   = $this->log;

		$rows = static function () use ( $log, $start ) {
			$after = max( 0, $start['start'] - 1 );
			while ( $start['start'] > 0 ) {
				$chunk = $log->replay_rows( $after, 1000 );
				if ( ! $chunk ) {
					return;
				}
				foreach ( $chunk as $row ) {
					yield $row;
					$after = (int) $row['id'];
				}
			}
		};

		$result = self::compare( $policies, $rows(), self::examples_limit(), time() + self::time_limit() );

		$result['days']      = $days;
		$result['since']     = $since;
		$result['truncated'] = $start['truncated'];

		/**
		 * Fires after a policy simulation ran. Nothing was changed by it.
		 *
		 * @param array $result   Counts per agent and per policy set, and what would change.
		 * @param array $policies The policy sets that were compared.
		 * @param int   $days     Days of history that were replayed.
		 */
		do_action( 'agent_governance_policy_simulated', $result, $policies, $days );

		return $result;
	}

	/**
	 * Decides every logged call under each policy set, oldest first.
	 *
	 * @param array<string, array> $policies Policy sets by name: rules, defaults, rate_limit and optionally rate_limit_for (callable taking the agent key). The first is the baseline.
	 * @param iterable             $rows     Log rows: created, identity, agent, user_id, via, source, target, method, kind.
	 * @param int                  $examples Example calls to keep for each agent and kind of change.
	 * @param int                  $deadline Unix time to stop at, 0 for none.
	 * @return array{calls: int, partial: bool, totals: array, agents: array}
	 */
	public static function compare( array $policies, iterable $rows, int $examples = 3, int $deadline = 0 ): array {
		$names    = array_keys( $policies );
		$baseline = $names ? $names[0] : '';
		$windows  = array();
		$limits   = array();
		$agents   = array();
		$totals   = self::blank( $names );
		$calls    = 0;
		$partial  = false;

		foreach ( $rows as $row ) {
			if ( $deadline && 0 === $calls % 200 && time() > $deadline ) {
				$partial = true;
				break;
			}

			++$calls;
			$key  = (string) $row['identity'];
			$time = (int) strtotime( $row['created'] . ' UTC' );
			$call = array(
				'source' => (string) $row['source'],
				'target' => (string) $row['target'],
				'method' => strtoupper( (string) $row['method'] ),
				'kind'   => in_array( $row['kind'], Settings::KINDS, true ) ? $row['kind'] : 'write',
				'input'  => null,
			);
			$who  = array(
				'key'     => $key,
				'label'   => (string) $row['agent'],
				'user_id' => (int) $row['user_id'],
				'via'     => (string) $row['via'],
				'session' => '',
			);

			if ( ! isset( $agents[ $key ] ) ) {
				$agents[ $key ] = array(
					'identity' => $key,
					'label'    => Log::identity_label( $row ),
					'calls'    => 0,
					'counts'   => self::blank( $names ),
					'changes'  => array(),
				);
			}
			++$agents[ $key ]['calls'];

			$outcomes = array();
			foreach ( $policies as $name => $policy ) {
				if ( ! isset( $windows[ $name ][ $key ] ) ) {
					$windows[ $name ][ $key ] = new \SplQueue();
				}
				$window = $windows[ $name ][ $key ];
				while ( ! $window->isEmpty() && $window->bottom() < $time - MINUTE_IN_SECONDS ) {
					$window->dequeue();
				}

				if ( ! isset( $limits[ $name ][ $key ] ) ) {
					$limits[ $name ][ $key ] = isset( $policy['rate_limit_for'] ) && is_callable( $policy['rate_limit_for'] ) ? (int) call_user_func( $policy['rate_limit_for'], $key ) : (int) ( $policy['rate_limit'] ?? 0 );
				}

				$decision = Policy::resolve(
					$call,
					$who,
					array(
						'rules'      => $policy['rules'] ?? array(),
						'defaults'   => $policy['defaults'] ?? array(),
						'rate_limit' => $limits[ $name ][ $key ],
					),
					array(
						'paused' => false,
						'recent' => count( $window ),
					)
				);

				// As the log counts them for the live limit: calls that ran or were held.
				if ( Settings::DENY !== $decision['action'] ) {
					$window->enqueue( $time );
				}

				$outcome           = Settings::DENY === $decision['action'] && 'rate_limit' === $decision['reason'] ? 'rate_limit' : $decision['action'];
				$outcomes[ $name ] = $outcome;
				++$agents[ $key ]['counts'][ $name ][ $outcome ];
				++$totals[ $name ][ $outcome ];
			}

			foreach ( $names as $name ) {
				if ( $name === $baseline || $outcomes[ $name ] === $outcomes[ $baseline ] ) {
					continue;
				}

				$change = $outcomes[ $baseline ] . '>' . $outcomes[ $name ];
				if ( ! isset( $agents[ $key ]['changes'][ $name ][ $change ] ) ) {
					$agents[ $key ]['changes'][ $name ][ $change ] = array(
						'from'     => $outcomes[ $baseline ],
						'to'       => $outcomes[ $name ],
						'count'    => 0,
						'examples' => array(),
					);
				}

				$entry = &$agents[ $key ]['changes'][ $name ][ $change ];
				++$entry['count'];
				if ( count( $entry['examples'] ) < $examples ) {
					$entry['examples'][] = array(
						'target'  => $call['target'],
						'method'  => $call['method'],
						'created' => $time,
					);
				}
				unset( $entry );
			}
		}

		// Agents with a change first, then by how many calls they made.
		uasort(
			$agents,
			static function ( array $a, array $b ): int {
				$by_change = (int) ! empty( $b['changes'] ) <=> (int) ! empty( $a['changes'] );

				return 0 !== $by_change ? $by_change : $b['calls'] <=> $a['calls'];
			}
		);

		return array(
			'policies' => $names,
			'calls'    => $calls,
			'partial'  => $partial,
			'totals'   => $totals,
			'changes'  => self::sum_changes( $agents ),
			'agents'   => $agents,
		);
	}

	/**
	 * A simulation result as plain lists, for WP-CLI, the ability and the screen.
	 *
	 * @param array  $result From run().
	 * @param string $policy The policy set to describe against the baseline.
	 * @return array days, calls, truncated, partial, baseline and policy (counts per outcome), changes and agents.
	 */
	public static function summary( array $result, string $policy = 'draft' ): array {
		$changes = static function ( array $items, bool $with_examples ): array {
			$out = array();
			foreach ( $items as $change ) {
				$row = array(
					'from'    => $change['from'],
					'to'      => $change['to'],
					'count'   => (int) $change['count'],
					'summary' => self::describe_change( $change['from'], $change['to'], (int) $change['count'] ),
				);
				if ( $with_examples ) {
					$row['examples'] = array_map(
						static function ( array $example ): array {
							return array(
								'call' => trim( $example['method'] . ' ' . $example['target'] ),
								'time' => gmdate( 'c', (int) $example['created'] ),
							);
						},
						$change['examples']
					);
				}
				$out[] = $row;
			}

			return $out;
		};

		$names    = $result['policies'] ?? array();
		$baseline = $names ? $names[0] : '';
		$agents   = array();
		foreach ( $result['agents'] as $agent ) {
			$agents[] = array(
				'identity' => $agent['identity'],
				'label'    => $agent['label'],
				'calls'    => $agent['calls'],
				'baseline' => $agent['counts'][ $baseline ] ?? array(),
				'policy'   => $agent['counts'][ $policy ] ?? array(),
				'changes'  => $changes( $agent['changes'][ $policy ] ?? array(), true ),
			);
		}

		return array(
			'days'      => (int) ( $result['days'] ?? 0 ),
			'calls'     => (int) $result['calls'],
			'truncated' => ! empty( $result['truncated'] ),
			'partial'   => ! empty( $result['partial'] ),
			'baseline'  => $result['totals'][ $baseline ] ?? array(),
			'policy'    => $result['totals'][ $policy ] ?? array(),
			'changes'   => $changes( array_values( $result['changes'][ $policy ] ?? array() ), false ),
			'agents'    => $agents,
		);
	}

	/**
	 * Zero counts for every outcome, per policy set.
	 *
	 * @param string[] $names Policy set names.
	 * @return array
	 */
	private static function blank( array $names ): array {
		$blank = array();
		foreach ( $names as $name ) {
			$blank[ $name ] = array_fill_keys( self::OUTCOMES, 0 );
		}

		return $blank;
	}

	/**
	 * Changes added up over every agent: how many calls went from one outcome to another, per policy set.
	 *
	 * @param array $agents Agents from compare().
	 * @return array<string, array<string, array{from: string, to: string, count: int}>>
	 */
	private static function sum_changes( array $agents ): array {
		$sum = array();
		foreach ( $agents as $agent ) {
			foreach ( $agent['changes'] as $name => $changes ) {
				foreach ( $changes as $key => $change ) {
					if ( ! isset( $sum[ $name ][ $key ] ) ) {
						$sum[ $name ][ $key ] = array(
							'from'  => $change['from'],
							'to'    => $change['to'],
							'count' => 0,
						);
					}
					$sum[ $name ][ $key ]['count'] += $change['count'];
				}
			}
		}

		return $sum;
	}

	/**
	 * A sentence for one kind of change, such as "14 calls that ran would have waited for approval".
	 *
	 * @param string $from  Baseline outcome.
	 * @param string $to    New outcome.
	 * @param int    $count Calls.
	 * @return string Plain text.
	 */
	public static function describe_change( string $from, string $to, int $count ): string {
		if ( 'allow' === $from && 'require_approval' === $to ) {
			/* translators: %d: number of calls. */
			return sprintf( _n( '%d call that ran would have waited for approval.', '%d calls that ran would have waited for approval.', $count, 'agent-governance' ), $count );
		}
		if ( 'allow' === $from && 'deny' === $to ) {
			/* translators: %d: number of calls. */
			return sprintf( _n( '%d call that ran would have been refused.', '%d calls that ran would have been refused.', $count, 'agent-governance' ), $count );
		}
		if ( 'allow' === $from && 'rate_limit' === $to ) {
			/* translators: %d: number of calls. */
			return sprintf( _n( '%d call that ran would have been rate limited.', '%d calls that ran would have been rate limited.', $count, 'agent-governance' ), $count );
		}
		if ( 'require_approval' === $from && 'allow' === $to ) {
			/* translators: %d: number of calls. */
			return sprintf( _n( '%d call that waited for approval would have run at once.', '%d calls that waited for approval would have run at once.', $count, 'agent-governance' ), $count );
		}
		if ( 'require_approval' === $from && in_array( $to, array( 'deny', 'rate_limit' ), true ) ) {
			/* translators: %d: number of calls. */
			return sprintf( _n( '%d call that waited for approval would have been refused.', '%d calls that waited for approval would have been refused.', $count, 'agent-governance' ), $count );
		}
		if ( in_array( $from, array( 'deny', 'rate_limit' ), true ) && 'allow' === $to ) {
			/* translators: %d: number of calls. */
			return sprintf( _n( '%d refused call would have run.', '%d refused calls would have run.', $count, 'agent-governance' ), $count );
		}
		if ( in_array( $from, array( 'deny', 'rate_limit' ), true ) && 'require_approval' === $to ) {
			/* translators: %d: number of calls. */
			return sprintf( _n( '%d refused call would have waited for approval.', '%d refused calls would have waited for approval.', $count, 'agent-governance' ), $count );
		}

		/* translators: 1: number of calls, 2: what happens to them today, 3: what would happen. */
		return sprintf( _n( '%1$d call would change from %2$s to %3$s.', '%1$d calls would change from %2$s to %3$s.', $count, 'agent-governance' ), $count, self::outcome_label( $from ), self::outcome_label( $to ) );
	}

	/**
	 * What an outcome is called on screen.
	 *
	 * @param string $outcome One of OUTCOMES.
	 * @return string
	 */
	public static function outcome_label( string $outcome ): string {
		$labels = array(
			'allow'            => __( 'Allowed', 'agent-governance' ),
			'require_approval' => __( 'Held for approval', 'agent-governance' ),
			'deny'             => __( 'Refused', 'agent-governance' ),
			'rate_limit'       => __( 'Rate limited', 'agent-governance' ),
		);

		return $labels[ $outcome ] ?? $outcome;
	}
}
