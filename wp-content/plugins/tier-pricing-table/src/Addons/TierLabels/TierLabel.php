<?php namespace TierPricingTable\Addons\TierLabels;

class TierLabel {
	
	private $id;
	private $title;
	private $backgroundColor;
	private $textColor;
	private $style;
	private $borderColor;
	private $CSSClass;
	
	private $fontSize;
	private $borderWidth;
	private $paddingX;
	private $paddingY;
	private $borderRadius;
	private $borderStyle;
	private $icon;
	
	public function __construct( $id, $args = array() ) {
		$args = wp_parse_args( $args, array(
			'title'            => '',
			'background_color' => '#333',
			'text_color'       => '#fff',
			'border_color'     => '',
			'style'            => 'square',
			'css_class'        => '',
			'font_size'        => 12,
			'border_width'     => 0,
			'padding_x'        => 8,
			'padding_y'        => 2,
			'border_radius'    => 4,
			'border_style'     => 'solid',
			'icon'             => '',
		) );
		
		$this->id              = $id;
		$this->title           = $args['title'];
		$this->backgroundColor = $args['background_color'];
		$this->textColor       = $args['text_color'];
		$this->borderColor     = $args['border_color'];
		$this->style           = $args['style'];
		$this->CSSClass        = $args['css_class'];
		$this->fontSize        = isset($args['font_size']) ? (int) $args['font_size'] : 12;
		$this->borderWidth     = isset($args['border_width']) ? (int) $args['border_width'] : 0;
		$this->paddingX        = isset($args['padding_x']) ? (int) $args['padding_x'] : 8;
		$this->paddingY        = isset($args['padding_y']) ? (int) $args['padding_y'] : 2;
		$this->borderRadius    = isset($args['border_radius']) ? (int) $args['border_radius'] : 4;
		$this->borderStyle     = $args['border_style'] ?? 'solid';
		$this->icon            = $args['icon'] ?? '';
	}
	
	public function getId() {
		return $this->id;
	}
	
	public function getTitle() {
		return $this->title;
	}
	
	public function getBackgroundColor() {
		return $this->backgroundColor;
	}
	
	public function getTextColor() {
		return $this->textColor;
	}
	
	public function getStyle() {
		return $this->style;
	}
	
	public function getBorderColor() {
		return $this->borderColor;
	}
	
	public function getFontSize() {
		return $this->fontSize;
	}
	
	public function getBorderWidth() {
		return $this->borderWidth;
	}
	
	public function getPaddingX() {
		return $this->paddingX;
	}
	
	public function getPaddingY() {
		return $this->paddingY;
	}
	
	public function getBorderRadius() {
		return $this->borderRadius;
	}
	
	public function getBorderStyle(): string {
		return $this->borderStyle;
	}
	
	public function getIcon(): string {
		return $this->icon;
	}
	
	public function getCSSClass(): ?string {
		return $this->CSSClass;
	}
	
	public function toArray(): array {
		return array(
			'id'               => $this->id,
			'title'            => $this->title,
			'background_color' => $this->backgroundColor,
			'text_color'       => $this->textColor,
			'border_color'     => $this->borderColor,
			'style'            => $this->style,
			'css_class'        => $this->CSSClass,
			'font_size'        => $this->fontSize,
			'border_width'     => $this->borderWidth,
			'padding_x'        => $this->paddingX,
			'padding_y'        => $this->paddingY,
			'border_radius'    => $this->borderRadius,
			'border_style'     => $this->borderStyle,
			'icon'             => $this->icon,
		);
	}
	
	public function isValid(): bool {
		return ! empty( $this->id ) && ! empty( $this->title );
	}
	
	public static function fromArray( array $data ): self {
		return new self( $data['id'], $data );
	}
	
	public function render(): string {
		$style = '';
		
		$bgColor     = $this->getBackgroundColor() ?: '#2271b1';
		$textColor   = $this->getTextColor() ?: '#ffffff';
		$borderColor = $this->getBorderColor();
		
		$fontSize     = $this->getFontSize() ?: 12;
		$borderWidth  = $this->getBorderWidth() !== null ? $this->getBorderWidth() : 0;
		$paddingX     = $this->getPaddingX() !== null ? $this->getPaddingX() : 8;
		$paddingY     = $this->getPaddingY() !== null ? $this->getPaddingY() : 2;
		$borderRadius = $this->getBorderRadius() !== null ? $this->getBorderRadius() : 4;
		$borderStyle  = $this->getBorderStyle() ?: 'solid';
		
		if ( $borderStyle === 'outline' || $borderStyle === 'dashed' ) {
			$style .= 'background-color: transparent;';
		} else {
			$style .= 'background-color: ' . esc_attr( $bgColor ) . ';';
		}
		
		$style .= 'color: ' . esc_attr( $textColor ) . ';';
		$style .= 'padding: ' . esc_attr( $paddingY ) . 'px ' . esc_attr( $paddingX ) . 'px;';
		$style .= 'font-size: ' . esc_attr( $fontSize ) . 'px;';
		$style .= 'font-weight: 600;';
		$style .= 'display: inline-block;';
		$style .= 'white-space: nowrap;';
		$style .= 'overflow: hidden;';
		$style .= 'width: max-content;';
		$style .= 'border-radius: ' . esc_attr( $borderRadius ) . 'px;';
		
		if ( $borderStyle === 'dashed' ) {
			$actualBorderColor = $borderColor ?: $textColor;
			$style             .= 'border: ' . esc_attr( $borderWidth ?: 2 ) . 'px dashed ' . esc_attr( $actualBorderColor ) . ';';
		} elseif ( $borderStyle === 'outline' ) {
			$actualBorderColor = $borderColor ?: $textColor;
			$style             .= 'border: ' . esc_attr( $borderWidth ?: 2 ) . 'px solid ' . esc_attr( $actualBorderColor ) . ';';
		} elseif ( $borderWidth > 0 ) {
			$style .= 'border: ' . esc_attr( $borderWidth ) . 'px solid ' . esc_attr( $borderColor ?: '#000000' ) . ';';
		}
		
		$cssClass = $this->getCSSClass() ? ' ' . esc_attr( $this->getCSSClass() ) : '';

		$iconSvg = '';
		if ( $this->getIcon() ) {
			$iconSvg = $this->getIconSvg( $this->getIcon() );
			// Adjust layout for icon + text
			$style .= 'display: inline-flex; align-items: center; justify-content: center; gap: 2px;';
		}
		
		$labelHTML = '<span id="' . esc_attr( $this->getId() ) . '" class="tiered-pricing-tier-label' . $cssClass . '" style="' . $style . '">' . $iconSvg . esc_html( $this->getTitle() ) . '</span>';
		
		return apply_filters( 'tiered_pricing_table/addons/tier_labels/label_html', $labelHTML, $this );
	}

	private function getIconSvg( string $iconName ): string {
		$icons = array(
			'star' => '<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>',
			'fire' => '<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"></path></svg>',
			'lightning' => '<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>',
			'tag' => '<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>',
			'crown' => '<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m2 4 3 12h14l3-12-6 7-4-7-4 7-6-7zm3 16h14"></path></svg>',
			'percent' => '<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="5" x2="5" y2="19"></line><circle cx="6.5" cy="6.5" r="2.5"></circle><circle cx="17.5" cy="17.5" r="2.5"></circle></svg>',
			'award' => '<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"></circle><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline></svg>',
		);

		return $icons[$iconName] ?? '';
	}
}