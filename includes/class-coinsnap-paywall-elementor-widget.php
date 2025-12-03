<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

class Coinsnap_Paywall_Elementor_Widget extends Widget_Base {
    
    public function get_name() {
        return 'Coinsnap Bitcoin Paywall Widget';
    }

    public function get_title() {
        return 'Coinsnap Bitcoin Paywall';
    }

    public function get_icon() {
        return 'eicon-shortcode';
    }

    public function get_categories() {
        return [ 'basic' ];
    }

    protected function _register_controls() {

        $this->start_controls_section(
            'section_content',
            [
                'label' => __( 'Coinsnap Bitcoin Paywall', 'coinsnap-paywall'),
            ]
        );

        $this->add_control(
            'shortcode_text',
            [
                'label' => __( 'Coinsnap Bitcoin Paywall', 'coinsnap-paywall'),
                'type' => Controls_Manager::TEXT,
                'default' => '[paywall_payment id=""]',
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();

        if ( ! empty( $settings['shortcode_text'] ) ) {
            echo do_shortcode( $settings['shortcode_text'] );
        }
    }
}