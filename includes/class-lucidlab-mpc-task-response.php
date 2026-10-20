<?php
/**
 * Task response exception.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Internal return boundary shared by the AJAX and cron execution paths. */
class LucidLab_MPC_Task_Response extends RuntimeException {
	/**
	 * Data.
	 *
	 * @var array
	 */
	public array $data;
	/**
	 * Constructor.
	 *
	 * @param array $data Data.
	 */
	public function __construct( array $data ) {
		parent::__construct( __( 'Task response', 'lucidlab-mpc-cleanup' ) );
		$this->data = $data; }
}
