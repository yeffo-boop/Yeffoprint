<?php
/**
 * YeffoHealth compound library: reference facts for common peptides,
 * hormones and medications (Me > Compound library, and the Progress
 * tab's estimated levels chart, which needs each half-life).
 *
 * Direct request (Jeff): a library like other peptide trackers have,
 * kept to facts so it reads as a reference, not dosing advice (App Store
 * guideline 1.4.2). So an entry is what it is, its approval status, its
 * half-life and how to store it. Never a dose, a mixing amount or what
 * it's "for". Half-lives are rounded figures from published studies;
 * `hl` is null where people haven't been studied enough to give one.
 *
 * Fields: n name, a also known as (brand names, other spellings), g
 * group, s status, hl half-life in hours, abs how quickly it's absorbed
 * from an injection or depot (absorption half-life in hours, for the
 * chart's rise), hn a note when the half-life needs one, k storage kind
 * (STORAGE below).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_Compounds {

	private const RX       = 'FDA-approved prescription medicine.';
	private const RESEARCH = 'Not FDA-approved for use in people. Sold for research.';
	private const TRIAL    = 'In clinical trials. Not FDA-approved yet.';

	/** How each kind is kept, before and after mixing. */
	private const STORAGE = [
		'peptide' => [
			'before' => 'Powder: in the fridge, 36–46°F (2–8°C), out of light. The freezer is fine for long storage.',
			'after'  => 'Once mixed: in the fridge, never frozen. Commonly used within about 4 weeks.',
		],
		'glp1'    => [
			'before' => 'Pens and vials: in the fridge, 36–46°F (2–8°C), until first use. Never frozen.',
			'after'  => 'After first use, a pen’s leaflet says how long it keeps. Mixed powder: in the fridge, commonly used within about 4 weeks.',
		],
		'hgh'     => [
			'before' => 'Powder: in the fridge, 36–46°F (2–8°C).',
			'after'  => 'Once mixed: in the fridge, never frozen or shaken. Most brands say use within 14–28 days.',
		],
		'hcg'     => [
			'before' => 'Powder: room temperature or the fridge, as the box says.',
			'after'  => 'Once mixed: in the fridge. Most brands say use within 30–60 days.',
		],
		'oil'     => [
			'before' => 'Room temperature, 68–77°F (20–25°C), out of light. Don’t refrigerate: the oil can crystallize.',
			'after'  => 'If crystals form, warm the vial in your hand and swirl until clear.',
		],
		'oral'    => [
			'before' => 'Room temperature, dry and out of light, in the bottle it came in.',
			'after'  => '',
		],
	];

	private const LIST = [
		// GLP-1 & weight
		[ 'n' => 'Semaglutide', 'a' => [ 'Ozempic', 'Wegovy', 'Rybelsus' ], 'g' => 'GLP-1 & weight', 's' => self::RX . ' Sold as Ozempic, Wegovy and Rybelsus (tablets).', 'hl' => 168, 'abs' => 12, 'k' => 'glp1' ],
		[ 'n' => 'Tirzepatide', 'a' => [ 'Mounjaro', 'Zepbound' ], 'g' => 'GLP-1 & weight', 's' => self::RX . ' Sold as Mounjaro and Zepbound.', 'hl' => 120, 'abs' => 5, 'k' => 'glp1' ],
		[ 'n' => 'Retatrutide', 'a' => [], 'g' => 'GLP-1 & weight', 's' => self::TRIAL, 'hl' => 144, 'abs' => 12, 'k' => 'peptide' ],
		[ 'n' => 'Liraglutide', 'a' => [ 'Victoza', 'Saxenda' ], 'g' => 'GLP-1 & weight', 's' => self::RX . ' Sold as Victoza and Saxenda.', 'hl' => 13, 'abs' => 5, 'k' => 'glp1' ],
		[ 'n' => 'Dulaglutide', 'a' => [ 'Trulicity' ], 'g' => 'GLP-1 & weight', 's' => self::RX . ' Sold as Trulicity.', 'hl' => 112, 'abs' => 12, 'k' => 'glp1' ],
		[ 'n' => 'Exenatide', 'a' => [ 'Byetta' ], 'g' => 'GLP-1 & weight', 's' => self::RX . ' Sold as Byetta. The weekly version (Bydureon) is made to release slowly, so it lasts much longer.', 'hl' => 2.4, 'k' => 'glp1' ],
		[ 'n' => 'Cagrilintide', 'a' => [], 'g' => 'GLP-1 & weight', 's' => self::TRIAL, 'hl' => 180, 'abs' => 12, 'k' => 'peptide' ],
		[ 'n' => 'Pramlintide', 'a' => [ 'Symlin' ], 'g' => 'GLP-1 & weight', 's' => self::RX . ' Sold as Symlin.', 'hl' => 0.8, 'k' => 'glp1' ],
		[ 'n' => 'AOD-9604', 'a' => [ 'AOD9604' ], 'g' => 'GLP-1 & weight', 's' => self::RESEARCH, 'hl' => null, 'k' => 'peptide' ],
		[ 'n' => 'MOTS-c', 'a' => [ 'MOTSc' ], 'g' => 'GLP-1 & weight', 's' => self::RESEARCH, 'hl' => null, 'k' => 'peptide' ],
		[ 'n' => '5-Amino-1MQ', 'a' => [ '5 Amino 1MQ' ], 'g' => 'GLP-1 & weight', 's' => self::RESEARCH, 'hl' => null, 'k' => 'oral' ],

		// Healing & recovery
		[ 'n' => 'BPC-157', 'a' => [ 'BPC157' ], 'g' => 'Healing & recovery', 's' => self::RESEARCH, 'hl' => null, 'hn' => 'Not measured in people. Animal studies suggest it clears within hours.', 'k' => 'peptide' ],
		[ 'n' => 'TB-500', 'a' => [ 'TB500', 'Thymosin Beta-4', 'Thymosin Beta 4' ], 'g' => 'Healing & recovery', 's' => self::RESEARCH, 'hl' => null, 'k' => 'peptide' ],
		[ 'n' => 'Thymosin Alpha-1', 'a' => [ 'TA-1', 'Thymosin Alpha 1', 'Zadaxin' ], 'g' => 'Healing & recovery', 's' => 'Approved in some countries as Zadaxin. Not FDA-approved.', 'hl' => 2, 'k' => 'peptide' ],
		[ 'n' => 'GHK-Cu', 'a' => [ 'GHK Cu', 'Copper peptide' ], 'g' => 'Healing & recovery', 's' => self::RESEARCH . ' Also used in skin creams.', 'hl' => null, 'k' => 'peptide' ],
		[ 'n' => 'KPV', 'a' => [], 'g' => 'Healing & recovery', 's' => self::RESEARCH, 'hl' => null, 'k' => 'peptide' ],
		[ 'n' => 'LL-37', 'a' => [ 'LL37' ], 'g' => 'Healing & recovery', 's' => self::RESEARCH, 'hl' => null, 'k' => 'peptide' ],

		// Growth hormone
		[ 'n' => 'Somatropin', 'a' => [ 'hGH', 'rhGH', 'Growth hormone', 'Genotropin', 'Norditropin', 'Omnitrope', 'Humatrope' ], 'g' => 'Growth hormone', 's' => self::RX, 'hl' => 3.5, 'hn' => 'About 3–4 hours after an injection under the skin, because it’s absorbed slowly.', 'k' => 'hgh' ],
		[ 'n' => 'Tesamorelin', 'a' => [ 'Egrifta' ], 'g' => 'Growth hormone', 's' => self::RX . ' Sold as Egrifta.', 'hl' => 0.5, 'k' => 'peptide' ],
		[ 'n' => 'Sermorelin', 'a' => [], 'g' => 'Growth hormone', 's' => 'Was FDA-approved (Geref) but is no longer made by a drug company.', 'hl' => 0.2, 'k' => 'peptide' ],
		[ 'n' => 'Ipamorelin', 'a' => [], 'g' => 'Growth hormone', 's' => self::RESEARCH, 'hl' => 2, 'k' => 'peptide' ],
		[ 'n' => 'CJC-1295 DAC', 'a' => [ 'CJC1295 DAC', 'CJC-1295 with DAC' ], 'g' => 'Growth hormone', 's' => self::RESEARCH, 'hl' => 168, 'hn' => 'About 6–8 days. The DAC part makes it last.', 'k' => 'peptide' ],
		[ 'n' => 'CJC-1295 no DAC', 'a' => [ 'Mod GRF 1-29', 'ModGRF 1-29', 'Mod GRF (1-29)', 'CJC1295 no DAC' ], 'g' => 'Growth hormone', 's' => self::RESEARCH, 'hl' => 0.5, 'k' => 'peptide' ],
		[ 'n' => 'CJC-1295', 'a' => [ 'CJC1295' ], 'g' => 'Growth hormone', 's' => self::RESEARCH, 'hl' => null, 'hn' => 'Depends on the version: with DAC about 6–8 days, without DAC (Mod GRF 1-29) about 30 minutes. Name yours “CJC-1295 DAC” or “CJC-1295 no DAC” to chart it.', 'k' => 'peptide' ],
		[ 'n' => 'GHRP-2', 'a' => [ 'GHRP2' ], 'g' => 'Growth hormone', 's' => self::RESEARCH, 'hl' => 0.5, 'k' => 'peptide' ],
		[ 'n' => 'GHRP-6', 'a' => [ 'GHRP6' ], 'g' => 'Growth hormone', 's' => self::RESEARCH, 'hl' => 0.3, 'k' => 'peptide' ],
		[ 'n' => 'Hexarelin', 'a' => [], 'g' => 'Growth hormone', 's' => self::RESEARCH, 'hl' => 1.2, 'k' => 'peptide' ],
		[ 'n' => 'IGF-1 LR3', 'a' => [ 'IGF1 LR3', 'Long R3 IGF-1' ], 'g' => 'Growth hormone', 's' => self::RESEARCH, 'hl' => 24, 'hn' => 'About 20–30 hours.', 'k' => 'peptide' ],
		[ 'n' => 'Fragment 176-191', 'a' => [ 'HGH Fragment 176-191', 'hGH Fragment 176-191', 'Frag 176-191' ], 'g' => 'Growth hormone', 's' => self::RESEARCH, 'hl' => null, 'k' => 'peptide' ],

		// Hormones
		[ 'n' => 'Testosterone Cypionate', 'a' => [ 'Test Cyp', 'Depo-Testosterone' ], 'g' => 'Hormones', 's' => self::RX . ' A Schedule III controlled substance.', 'hl' => 192, 'abs' => 12, 'hn' => 'About 8 days from an injection into the muscle, because the oil releases it slowly.', 'k' => 'oil' ],
		[ 'n' => 'Testosterone Enanthate', 'a' => [ 'Test E', 'Xyosted' ], 'g' => 'Hormones', 's' => self::RX . ' A Schedule III controlled substance.', 'hl' => 108, 'abs' => 12, 'hn' => 'About 4–5 days from an injection, because the oil releases it slowly.', 'k' => 'oil' ],
		[ 'n' => 'Nandrolone Decanoate', 'a' => [ 'Deca' ], 'g' => 'Hormones', 's' => self::RX . ' A Schedule III controlled substance.', 'hl' => 168, 'abs' => 12, 'hn' => 'About 6–12 days from an injection, because the oil releases it slowly.', 'k' => 'oil' ],
		[ 'n' => 'HCG', 'a' => [ 'hCG', 'Human chorionic gonadotropin', 'Pregnyl', 'Novarel' ], 'g' => 'Hormones', 's' => self::RX . ' Sold as Pregnyl and Novarel.', 'hl' => 30, 'hn' => 'About 1 to 1½ days.', 'k' => 'hcg' ],
		[ 'n' => 'Gonadorelin', 'a' => [], 'g' => 'Hormones', 's' => self::RESEARCH, 'hl' => 0.2, 'k' => 'peptide' ],
		[ 'n' => 'Bremelanotide', 'a' => [ 'PT-141', 'PT141', 'Vyleesi' ], 'g' => 'Hormones', 's' => self::RX . ' Sold as Vyleesi.', 'hl' => 2.7, 'k' => 'peptide' ],
		[ 'n' => 'Kisspeptin-10', 'a' => [ 'Kisspeptin' ], 'g' => 'Hormones', 's' => self::RESEARCH, 'hl' => null, 'hn' => 'A few minutes.', 'k' => 'peptide' ],
		[ 'n' => 'Oxytocin', 'a' => [], 'g' => 'Hormones', 's' => self::RX . ' Given in hospitals. Nasal and other forms come from compounding pharmacies.', 'hl' => null, 'hn' => 'A few minutes.', 'k' => 'peptide' ],

		// Other peptides
		[ 'n' => 'Melanotan II', 'a' => [ 'MT2', 'MT-2', 'Melanotan 2' ], 'g' => 'Other peptides', 's' => self::RESEARCH, 'hl' => null, 'k' => 'peptide' ],
		[ 'n' => 'Epitalon', 'a' => [ 'Epithalon' ], 'g' => 'Other peptides', 's' => self::RESEARCH, 'hl' => null, 'k' => 'peptide' ],
		[ 'n' => 'Selank', 'a' => [], 'g' => 'Other peptides', 's' => 'Approved in Russia. Not FDA-approved.', 'hl' => null, 'hn' => 'Minutes.', 'k' => 'peptide' ],
		[ 'n' => 'Semax', 'a' => [], 'g' => 'Other peptides', 's' => 'Approved in Russia. Not FDA-approved.', 'hl' => null, 'hn' => 'Minutes.', 'k' => 'peptide' ],
		[ 'n' => 'DSIP', 'a' => [ 'Delta sleep-inducing peptide' ], 'g' => 'Other peptides', 's' => self::RESEARCH, 'hl' => null, 'k' => 'peptide' ],
		[ 'n' => 'NAD+', 'a' => [ 'NAD' ], 'g' => 'Other peptides', 's' => 'A coenzyme every cell makes. Sold as a supplement and by compounding pharmacies.', 'hl' => null, 'k' => 'peptide' ],

		// Common medications
		[ 'n' => 'Tadalafil', 'a' => [ 'Cialis' ], 'g' => 'Medications', 's' => self::RX . ' Sold as Cialis.', 'hl' => 17.5, 'k' => 'oral' ],
		[ 'n' => 'Sildenafil', 'a' => [ 'Viagra' ], 'g' => 'Medications', 's' => self::RX . ' Sold as Viagra.', 'hl' => 4, 'k' => 'oral' ],
		[ 'n' => 'Anastrozole', 'a' => [ 'Arimidex' ], 'g' => 'Medications', 's' => self::RX . ' Sold as Arimidex.', 'hl' => 50, 'k' => 'oral' ],
		[ 'n' => 'Clomiphene', 'a' => [ 'Clomid' ], 'g' => 'Medications', 's' => self::RX . ' Sold as Clomid.', 'hl' => 120, 'k' => 'oral' ],
		[ 'n' => 'Enclomiphene', 'a' => [], 'g' => 'Medications', 's' => 'Not FDA-approved. Some compounding pharmacies make it.', 'hl' => 10, 'k' => 'oral' ],
		[ 'n' => 'Tamoxifen', 'a' => [ 'Nolvadex' ], 'g' => 'Medications', 's' => self::RX, 'hl' => 168, 'hn' => 'About 5–7 days.', 'k' => 'oral' ],
		[ 'n' => 'Metformin', 'a' => [], 'g' => 'Medications', 's' => self::RX, 'hl' => 6, 'k' => 'oral' ],
		[ 'n' => 'Finasteride', 'a' => [ 'Propecia', 'Proscar' ], 'g' => 'Medications', 's' => self::RX, 'hl' => 6, 'k' => 'oral' ],
	];

	/**
	 * The library as the app wants it, each storage kind written out.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		return array_map( static function ( array $row ): array {
			$store = self::STORAGE[ $row['k'] ] ?? self::STORAGE['peptide'];
			unset( $row['k'] );
			return $row + [ 'abs' => null, 'hn' => '', 'before' => $store['before'], 'after' => $store['after'] ];
		}, self::LIST );
	}
}
