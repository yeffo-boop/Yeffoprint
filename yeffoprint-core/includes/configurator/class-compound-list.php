<?php
/**
 * Compound List: the peptide/hormone/compound names the storefront's
 * compound spell-check compares against (theme assets/js/label-
 * proofing.js), on template labels' Compound Name field and the custom
 * label form's Product details.
 *
 * Direct request: flag likely misspellings ("Did you mean Semaglutide?")
 * and case slips on names like hGH/hCG, which are written with a
 * lowercase h. The check only ever asks; the customer can keep what they
 * typed.
 *
 * Each row is a correct spelling, the group it's listed under, and
 * "also typed as" spellings that should be corrected to it (HGH → hGH,
 * BPC157 → BPC-157). A spelling that's valid in its own right
 * (Epithalon and Epitalon) is its own row instead, so it's never
 * "corrected".
 *
 * One option holds the whole list. Until it's first saved from the
 * admin (Catalog → Compound List) the starter list below is used as is,
 * so the check works on day one without a setup step. "Add missing
 * starter names" in the admin merges in any starter row the saved list
 * doesn't have yet, for when this list grows later.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Compound_List {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public const LIST_OPTION = 'yeffoprint_compound_list';

	public const ENABLED_OPTION = 'yeffoprint_compound_check_enabled';

	private const MAX_ROWS = 1000;

	/**
	 * [ correct spelling, group, [ also typed as ] ]
	 */
	private const STARTER = [
		// GLP-1 & weight
		[ 'Semaglutide', 'GLP-1 & weight', [ 'Semaglutid', 'Semiglutide' ] ],
		[ 'Tirzepatide', 'GLP-1 & weight', [ 'Tirzepatid', 'Terzepatide' ] ],
		[ 'Retatrutide', 'GLP-1 & weight', [ 'Retatrutid', 'Reta-trutide' ] ],
		[ 'Cagrilintide', 'GLP-1 & weight', [] ],
		[ 'CagriSema', 'GLP-1 & weight', [] ],
		[ 'Liraglutide', 'GLP-1 & weight', [] ],
		[ 'Dulaglutide', 'GLP-1 & weight', [] ],
		[ 'Exenatide', 'GLP-1 & weight', [] ],
		[ 'Mazdutide', 'GLP-1 & weight', [] ],
		[ 'Survodutide', 'GLP-1 & weight', [] ],
		[ 'Orforglipron', 'GLP-1 & weight', [] ],
		[ 'Pramlintide', 'GLP-1 & weight', [] ],
		[ 'Pemvidutide', 'GLP-1 & weight', [] ],
		[ 'AOD-9604', 'GLP-1 & weight', [ 'AOD9604', 'AOD 9604' ] ],
		[ '5-Amino-1MQ', 'GLP-1 & weight', [ '5 Amino 1MQ', '5-Amino1MQ', '5 amino 1mq' ] ],
		[ 'Tesofensine', 'GLP-1 & weight', [] ],
		[ 'SLU-PP-332', 'GLP-1 & weight', [ 'SLUPP332', 'SLU PP 332', 'SLU-PP332' ] ],
		[ 'MOTS-c', 'GLP-1 & weight', [ 'MOTS-C', 'MOTSc', 'MOTS C', 'Mots-c' ] ],
		[ 'Adipotide', 'GLP-1 & weight', [] ],
		[ 'AICAR', 'GLP-1 & weight', [] ],
		[ 'L-Carnitine', 'GLP-1 & weight', [ 'L Carnitine', 'LCarnitine' ] ],
		[ 'Lipo-C', 'GLP-1 & weight', [ 'LipoC', 'Lipo C' ] ],
		[ 'Lipo-B', 'GLP-1 & weight', [ 'LipoB', 'Lipo B' ] ],
		[ 'MIC-B12', 'GLP-1 & weight', [ 'MIC B12', 'MICB12' ] ],

		// Healing & recovery
		[ 'BPC-157', 'Healing & recovery', [ 'BPC157', 'BPC 157', 'BCP-157', 'BCP157' ] ],
		[ 'TB-500', 'Healing & recovery', [ 'TB500', 'TB 500' ] ],
		[ 'Thymosin Beta-4', 'Healing & recovery', [ 'Thymosin Beta 4', 'Thymosin B4', 'Thymosin B-4' ] ],
		[ 'Thymosin Alpha-1', 'Healing & recovery', [ 'Thymosin Alpha 1', 'Thymosin A1', 'Thymosin A-1' ] ],
		[ 'TA-1', 'Healing & recovery', [ 'TA1' ] ],
		[ 'GHK-Cu', 'Healing & recovery', [ 'GHK Cu', 'GHKCu', 'GHK-CU', 'GHK-cu' ] ],
		[ 'GHK', 'Healing & recovery', [] ],
		[ 'KPV', 'Healing & recovery', [] ],
		[ 'LL-37', 'Healing & recovery', [ 'LL37', 'LL 37' ] ],
		[ 'ARA-290', 'Healing & recovery', [ 'ARA290', 'ARA 290' ] ],
		[ 'Pentadeca Arginate', 'Healing & recovery', [ 'PentaDeca Arginate', 'Pentadecaarginate' ] ],
		[ 'PDA', 'Healing & recovery', [] ],
		[ 'Larazotide', 'Healing & recovery', [] ],
		[ 'VIP', 'Healing & recovery', [] ],
		[ 'Thymalin', 'Healing & recovery', [] ],
		[ 'Thymulin', 'Healing & recovery', [] ],
		[ 'Vilon', 'Healing & recovery', [] ],
		[ 'Cardiogen', 'Healing & recovery', [] ],
		[ 'Livagen', 'Healing & recovery', [] ],
		[ 'Bronchogen', 'Healing & recovery', [] ],
		[ 'FOXO4-DRI', 'Healing & recovery', [ 'FOXO4 DRI', 'FOXO4DRI' ] ],
		[ 'PNC-27', 'Healing & recovery', [ 'PNC27', 'PNC 27' ] ],
		[ 'Glutathione', 'Healing & recovery', [ 'Glutathion', 'Gluthathione', 'Glutathoine' ] ],
		[ 'NAD+', 'Healing & recovery', [ 'NAD Plus' ] ],
		[ 'NAD', 'Healing & recovery', [] ],
		[ 'NMN', 'Healing & recovery', [] ],
		[ 'Methylene Blue', 'Healing & recovery', [] ],

		// Growth hormone & secretagogues
		[ 'hGH', 'Growth hormone', [ 'HGH', 'Hgh' ] ],
		[ 'rhGH', 'Growth hormone', [ 'RHGH', 'rHGH' ] ],
		[ 'Somatropin', 'Growth hormone', [] ],
		[ 'hGH Fragment 176-191', 'Growth hormone', [ 'HGH Fragment 176-191', 'HGH Frag 176-191', 'hGH Frag 176-191' ] ],
		[ 'Fragment 176-191', 'Growth hormone', [ 'Frag 176-191', 'Fragment 176 191' ] ],
		[ 'CJC-1295', 'Growth hormone', [ 'CJC1295', 'CJC 1295' ] ],
		[ 'CJC-1295 DAC', 'Growth hormone', [ 'CJC1295 DAC', 'CJC 1295 DAC' ] ],
		[ 'CJC-1295 no DAC', 'Growth hormone', [ 'CJC1295 no DAC', 'CJC 1295 no DAC' ] ],
		[ 'Mod GRF 1-29', 'Growth hormone', [ 'ModGRF 1-29', 'Mod GRF (1-29)', 'Mod-GRF 1-29' ] ],
		[ 'Ipamorelin', 'Growth hormone', [ 'Ipamorellin', 'Ipamoreline' ] ],
		[ 'Sermorelin', 'Growth hormone', [ 'Sermorellin' ] ],
		[ 'Tesamorelin', 'Growth hormone', [ 'Tesamorellin' ] ],
		[ 'Hexarelin', 'Growth hormone', [] ],
		[ 'GHRP-2', 'Growth hormone', [ 'GHRP2', 'GHRP 2' ] ],
		[ 'GHRP-6', 'Growth hormone', [ 'GHRP6', 'GHRP 6' ] ],
		[ 'MK-677', 'Growth hormone', [ 'MK677', 'MK 677' ] ],
		[ 'Ibutamoren', 'Growth hormone', [] ],
		[ 'IGF-1', 'Growth hormone', [ 'IGF1', 'IGF 1' ] ],
		[ 'IGF-1 LR3', 'Growth hormone', [ 'IGF1 LR3', 'IGF-1LR3', 'IGF 1 LR3', 'IGF1-LR3' ] ],
		[ 'IGF-1 DES', 'Growth hormone', [ 'IGF1 DES', 'IGF 1 DES', 'IGF1-DES' ] ],
		[ 'MGF', 'Growth hormone', [] ],
		[ 'PEG-MGF', 'Growth hormone', [ 'PEG MGF', 'PEGMGF' ] ],
		[ 'Follistatin', 'Growth hormone', [] ],
		[ 'Follistatin 344', 'Growth hormone', [ 'Follistatin-344', 'FST-344' ] ],
		[ 'ACE-031', 'Growth hormone', [ 'ACE031', 'ACE 031' ] ],

		// Hormones
		[ 'hCG', 'Hormones', [ 'HCG', 'Hcg' ] ],
		[ 'hMG', 'Hormones', [ 'HMG', 'Hmg' ] ],
		[ 'FSH', 'Hormones', [] ],
		[ 'Kisspeptin', 'Hormones', [] ],
		[ 'Kisspeptin-10', 'Hormones', [ 'Kisspeptin 10', 'Kisspeptin10' ] ],
		[ 'Gonadorelin', 'Hormones', [ 'Gonadorellin' ] ],
		[ 'Triptorelin', 'Hormones', [] ],
		[ 'Oxytocin', 'Hormones', [ 'Oxytocine' ] ],
		[ 'Vasopressin', 'Hormones', [] ],
		[ 'Teriparatide', 'Hormones', [] ],
		[ 'Testosterone Cypionate', 'Hormones', [] ],
		[ 'Testosterone Enanthate', 'Hormones', [ 'Testosterone Enanthade' ] ],
		[ 'Testosterone Propionate', 'Hormones', [] ],
		[ 'Testosterone Undecanoate', 'Hormones', [] ],
		[ 'Testosterone', 'Hormones', [] ],
		[ 'Nandrolone Decanoate', 'Hormones', [] ],
		[ 'Nandrolone', 'Hormones', [] ],
		[ 'DHEA', 'Hormones', [] ],
		[ 'Pregnenolone', 'Hormones', [] ],
		[ 'Progesterone', 'Hormones', [] ],
		[ 'Estradiol', 'Hormones', [] ],
		[ 'Estradiol Valerate', 'Hormones', [] ],
		[ 'Estradiol Cypionate', 'Hormones', [] ],
		[ 'Melatonin', 'Hormones', [] ],
		[ 'Liothyronine', 'Hormones', [] ],
		[ 'Levothyroxine', 'Hormones', [] ],
		[ 'Enclomiphene', 'Hormones', [] ],
		[ 'Clomiphene', 'Hormones', [] ],
		[ 'Anastrozole', 'Hormones', [ 'Anastrazole' ] ],
		[ 'Letrozole', 'Hormones', [] ],
		[ 'Exemestane', 'Hormones', [] ],
		[ 'Tamoxifen', 'Hormones', [] ],
		[ 'Cabergoline', 'Hormones', [] ],
		[ 'Tadalafil', 'Hormones', [] ],
		[ 'Sildenafil', 'Hormones', [] ],

		// Brain & sleep
		[ 'Semax', 'Brain & sleep', [] ],
		[ 'Selank', 'Brain & sleep', [] ],
		[ 'N-Acetyl Semax', 'Brain & sleep', [ 'N Acetyl Semax', 'NAcetyl Semax' ] ],
		[ 'N-Acetyl Selank', 'Brain & sleep', [ 'N Acetyl Selank', 'NAcetyl Selank' ] ],
		[ 'NA-Semax', 'Brain & sleep', [ 'NA Semax' ] ],
		[ 'NA-Selank', 'Brain & sleep', [ 'NA Selank' ] ],
		[ 'NA-Semax-Amidate', 'Brain & sleep', [ 'NA Semax Amidate' ] ],
		[ 'Dihexa', 'Brain & sleep', [] ],
		[ 'Cerebrolysin', 'Brain & sleep', [] ],
		[ 'Cortexin', 'Brain & sleep', [] ],
		[ 'P21', 'Brain & sleep', [] ],
		[ 'PE-22-28', 'Brain & sleep', [ 'PE 22-28', 'PE22-28', 'PE2228' ] ],
		[ 'DSIP', 'Brain & sleep', [] ],
		[ 'Pinealon', 'Brain & sleep', [] ],
		[ 'Epitalon', 'Brain & sleep', [] ],
		[ 'Epithalon', 'Brain & sleep', [] ],
		[ 'Humanin', 'Brain & sleep', [] ],
		[ 'SS-31', 'Brain & sleep', [ 'SS31', 'SS 31' ] ],
		[ 'Elamipretide', 'Brain & sleep', [] ],
		[ 'Noopept', 'Brain & sleep', [] ],
		[ 'Adamax', 'Brain & sleep', [] ],

		// Tanning & libido
		[ 'Melanotan II', 'Tanning & libido', [ 'Melanotan 2', 'Melanotan-2', 'Melanotan-II' ] ],
		[ 'MT-2', 'Tanning & libido', [ 'MT2', 'MT II', 'MT-II' ] ],
		[ 'Melanotan I', 'Tanning & libido', [ 'Melanotan 1', 'Melanotan-1', 'Melanotan-I' ] ],
		[ 'MT-1', 'Tanning & libido', [ 'MT1' ] ],
		[ 'Afamelanotide', 'Tanning & libido', [] ],
		[ 'PT-141', 'Tanning & libido', [ 'PT141', 'PT 141' ] ],
		[ 'Bremelanotide', 'Tanning & libido', [] ],

		// Skin & cosmetic
		[ 'Argireline', 'Skin & cosmetic', [] ],
		[ 'Matrixyl', 'Skin & cosmetic', [] ],
		[ 'SNAP-8', 'Skin & cosmetic', [ 'SNAP8', 'Snap 8' ] ],
		[ 'Leuphasyl', 'Skin & cosmetic', [] ],
		[ 'Syn-Ake', 'Skin & cosmetic', [ 'SynAke', 'Syn Ake' ] ],
		[ 'Copper Peptide', 'Skin & cosmetic', [] ],
		[ 'Hyaluronic Acid', 'Skin & cosmetic', [ 'Hyularonic Acid', 'Hyaluronic Acide' ] ],
		[ 'Retinol', 'Skin & cosmetic', [] ],
		[ 'Niacinamide', 'Skin & cosmetic', [] ],
		[ 'Collagen', 'Skin & cosmetic', [] ],

		// Vitamins & supplies
		[ 'B12', 'Vitamins & supplies', [ 'B-12', 'B 12' ] ],
		[ 'Methylcobalamin', 'Vitamins & supplies', [] ],
		[ 'Cyanocobalamin', 'Vitamins & supplies', [] ],
		[ 'Biotin', 'Vitamins & supplies', [] ],
		[ 'Ascorbic Acid', 'Vitamins & supplies', [] ],
		[ 'Bacteriostatic Water', 'Vitamins & supplies', [ 'Bacteriostatic H2O', 'Bacteriostat Water' ] ],
		[ 'BAC Water', 'Vitamins & supplies', [ 'Bac Water', 'BAC-Water' ] ],
		[ 'Sterile Water', 'Vitamins & supplies', [] ],
		[ 'Acetic Acid', 'Vitamins & supplies', [] ],
	];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/compound-list', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_list' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'save_list' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );
	}

	public function get_list(): \WP_REST_Response {
		return rest_ensure_response( self::admin_payload() );
	}

	public function save_list( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params() ?: [];

		if ( array_key_exists( 'enabled', $params ) ) {
			update_option( self::ENABLED_OPTION, empty( $params['enabled'] ) ? 'no' : 'yes' );
		}

		if ( ! empty( $params['add_missing_starter'] ) ) {
			update_option( self::LIST_OPTION, self::with_missing_starter( self::get_compounds() ), false );
		} elseif ( isset( $params['compounds'] ) && is_array( $params['compounds'] ) ) {
			update_option( self::LIST_OPTION, self::sanitize_rows( $params['compounds'] ), false );
		}

		return rest_ensure_response( self::admin_payload() );
	}

	public static function is_enabled(): bool {
		return 'no' !== get_option( self::ENABLED_OPTION, 'yes' );
	}

	/**
	 * @return array<int, array{name:string, group:string, aliases:string[]}>
	 */
	public static function get_compounds(): array {
		$saved = get_option( self::LIST_OPTION, null );

		return is_array( $saved ) ? $saved : self::starter_rows();
	}

	/**
	 * What the storefront script needs, kept compact since it's printed
	 * into the page: [ name, [ also typed as ] ] per row.
	 */
	public static function storefront_payload(): array {
		if ( ! self::is_enabled() ) {
			return [ 'enabled' => false, 'compounds' => [] ];
		}

		return [
			'enabled'   => true,
			'compounds' => array_map( function ( $row ) {
				return [ $row['name'], $row['aliases'] ];
			}, self::get_compounds() ),
		];
	}

	private static function admin_payload(): array {
		$compounds = self::get_compounds();

		return [
			'enabled'         => self::is_enabled(),
			'compounds'       => $compounds,
			'groups'          => array_values( array_unique( array_column( self::starter_rows(), 'group' ) ) ),
			'missing_starter' => count( self::with_missing_starter( $compounds ) ) - count( $compounds ),
		];
	}

	private static function starter_rows(): array {
		return array_map( function ( $row ) {
			return [ 'name' => $row[0], 'group' => $row[1], 'aliases' => $row[2] ];
		}, self::STARTER );
	}

	private static function with_missing_starter( array $rows ): array {
		$have = [];
		foreach ( $rows as $row ) {
			$have[ strtolower( $row['name'] ) ] = true;
		}

		foreach ( self::starter_rows() as $row ) {
			if ( ! isset( $have[ strtolower( $row['name'] ) ] ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Trims, drops empty and duplicate names (first one wins), and drops
	 * an "also typed as" that is just the name itself.
	 */
	private static function sanitize_rows( array $raw ): array {
		$rows = [];
		$seen = [];

		foreach ( array_slice( $raw, 0, self::MAX_ROWS ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = trim( sanitize_text_field( (string) ( $row['name'] ?? '' ) ) );
			$key  = strtolower( $name );
			if ( '' === $name || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$aliases = [];
			foreach ( (array) ( $row['aliases'] ?? [] ) as $alias ) {
				$alias = trim( sanitize_text_field( (string) $alias ) );
				if ( '' !== $alias && $alias !== $name && ! in_array( $alias, $aliases, true ) ) {
					$aliases[] = $alias;
				}
			}

			$rows[] = [
				'name'    => $name,
				'group'   => trim( sanitize_text_field( (string) ( $row['group'] ?? '' ) ) ),
				'aliases' => array_slice( $aliases, 0, 20 ),
			];
		}

		return $rows;
	}
}
