<?php
namespace Automattic\WCShipping\LegacyAPIControllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class WC_REST_Connect_Shipping_Label_Preview_Controller extends WC_REST_Connect_Base_Controller {
	protected $rest_base = 'connect/label/preview';

	public function get( $request ) {
		$raw_params = $request->get_params();
		$params     = array();

		$params['paper_size'] = $raw_params['paper_size'];
		$this->settings_store->set_preferred_paper_size( $params['paper_size'] );
		$params['carrier'] = 'usps';
		$params['labels']  = array();
		$captions          = empty( $raw_params['caption_csv'] ) ? array() : explode( ',', $raw_params['caption_csv'] );

		foreach ( $captions as $caption ) {
			$params['labels'][] = array( 'caption' => urldecode( $caption ) );
		}

		$raw_response = $this->api_client->get_labels_preview_pdf( $params );

		if ( is_wp_error( $raw_response ) ) {
			$this->logger->log( $raw_response, __CLASS__ );
			return $raw_response;
		}

		// WP REST is designed to handle responses as JSON, so if we wish to output the PDF as a raw response,
		// then we have to handle how we serve the response ourselves.
		header( 'content-type: ' . $raw_response['headers']['content-type'] );
		echo $raw_response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		die();
	}
}
