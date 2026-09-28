<?php
/**
 * Common medications and supplements for the Dose Tracker's name
 * autocomplete, so it works beyond peptides.
 *
 * Direct request: track more than peptides, with more ways to take them
 * (oral, nasal spray…). Suggestions only: the name field stays free
 * text, so anything not listed can still be typed. The peptide and
 * hormone names come from Catalog > Compound List as before; this list
 * adds everyday prescriptions, over-the-counter medicines and
 * supplements.
 *
 * Each name is grouped under the route it's usually taken by, which the
 * tracker pre-selects when the name is picked (the customer can still
 * change it).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_Medications {

	/** Route value (as stored on a protocol) => names. */
	private const BY_ROUTE = [
		'Oral'         => [
			// Heart, blood pressure, cholesterol
			'Amlodipine', 'Atorvastatin', 'Rosuvastatin', 'Simvastatin', 'Pravastatin', 'Ezetimibe', 'Lisinopril', 'Losartan', 'Valsartan', 'Olmesartan', 'Telmisartan', 'Hydrochlorothiazide', 'Chlorthalidone', 'Furosemide', 'Spironolactone', 'Metoprolol', 'Carvedilol', 'Propranolol', 'Atenolol', 'Clonidine', 'Diltiazem', 'Apixaban', 'Rivaroxaban', 'Warfarin', 'Clopidogrel', 'Aspirin', 'Digoxin',
			// Diabetes & weight
			'Metformin', 'Glipizide', 'Glimepiride', 'Pioglitazone', 'Sitagliptin', 'Empagliflozin', 'Dapagliflozin', 'Rybelsus (oral semaglutide)', 'Berberine', 'Phentermine', 'Topiramate', 'Naltrexone', 'Low Dose Naltrexone (LDN)', 'Bupropion',
			// Thyroid & hormones
			'Armour Thyroid', 'NP Thyroid', 'Finasteride', 'Dutasteride', 'Raloxifene', 'Norethindrone', 'Birth control pill', 'Medroxyprogesterone',
			// Mental health & sleep
			'Sertraline', 'Escitalopram', 'Citalopram', 'Fluoxetine', 'Paroxetine', 'Venlafaxine', 'Duloxetine', 'Desvenlafaxine', 'Mirtazapine', 'Trazodone', 'Buspirone', 'Hydroxyzine', 'Lamotrigine', 'Lithium', 'Quetiapine', 'Aripiprazole', 'Olanzapine', 'Risperidone', 'Alprazolam', 'Clonazepam', 'Lorazepam', 'Diazepam', 'Zolpidem', 'Eszopiclone', 'Doxepin',
			// ADHD & focus
			'Adderall (amphetamine salts)', 'Vyvanse (lisdexamfetamine)', 'Methylphenidate', 'Atomoxetine', 'Guanfacine', 'Modafinil', 'Armodafinil',
			// Pain & inflammation
			'Ibuprofen', 'Naproxen', 'Acetaminophen', 'Celecoxib', 'Meloxicam', 'Diclofenac', 'Tramadol', 'Gabapentin', 'Pregabalin', 'Cyclobenzaprine', 'Methocarbamol', 'Tizanidine', 'Prednisone', 'Methylprednisolone', 'Dexamethasone', 'Sumatriptan', 'Rizatriptan', 'Colchicine', 'Allopurinol',
			// Stomach
			'Omeprazole', 'Pantoprazole', 'Esomeprazole', 'Famotidine', 'Ondansetron', 'Metoclopramide', 'Loperamide', 'Docusate', 'Polyethylene Glycol (MiraLAX)', 'Simethicone',
			// Allergy & cold
			'Cetirizine', 'Loratadine', 'Fexofenadine', 'Levocetirizine', 'Diphenhydramine', 'Montelukast', 'Pseudoephedrine', 'Guaifenesin', 'Dextromethorphan',
			// Antibiotics & antivirals
			'Amoxicillin', 'Amoxicillin-Clavulanate', 'Azithromycin', 'Doxycycline', 'Cephalexin', 'Ciprofloxacin', 'Levofloxacin', 'Nitrofurantoin', 'Sulfamethoxazole-Trimethoprim', 'Clindamycin', 'Metronidazole', 'Fluconazole', 'Valacyclovir', 'Acyclovir', 'Oseltamivir', 'Ivermectin', 'Hydroxychloroquine',
			// Other prescriptions
			'Isotretinoin', 'Minoxidil (oral)', 'Oxybutynin', 'Tamsulosin', 'Rapamycin (sirolimus)', 'Acarbose', 'Pyridostigmine', 'Potassium Chloride',
			// Vitamins & supplements
			'Multivitamin', 'Vitamin A', 'Vitamin B Complex', 'Vitamin B6', 'Vitamin C', 'Vitamin D3', 'Vitamin D3 + K2', 'Vitamin E', 'Vitamin K2', 'Folate', 'Methylfolate', 'Iron', 'Calcium', 'Magnesium', 'Magnesium Glycinate', 'Magnesium Citrate', 'Magnesium L-Threonate', 'Zinc', 'Selenium', 'Iodine', 'Potassium', 'Boron', 'Omega-3 Fish Oil', 'Krill Oil', 'CoQ10', 'Ubiquinol', 'Creatine', 'L-Glutamine', 'L-Arginine', 'L-Citrulline', 'L-Theanine', 'Taurine', 'Glycine', 'NAC (N-Acetyl Cysteine)', 'Alpha Lipoic Acid', 'Resveratrol', 'Quercetin', 'Fisetin', 'Spermidine', 'TMG (Betaine)', 'Curcumin', 'Turmeric', 'Ashwagandha', 'Rhodiola', 'Tongkat Ali', 'Fadogia Agrestis', 'Maca', 'Ginseng', 'Ginkgo Biloba', 'Lion’s Mane', 'Saw Palmetto', 'Milk Thistle', 'Probiotic', 'Psyllium Husk', 'Fiber', 'Apigenin', 'Inositol', 'DIM', 'Calcium D-Glucarate', 'Electrolytes', 'Collagen Peptides', 'Whey Protein', 'Caffeine', 'Nicotinamide Riboside (NR)', 'Urolithin A', 'Astaxanthin', 'Lutein', 'Beet Root', 'Black Seed Oil', 'Elderberry', 'Garlic', 'Cinnamon', 'Chromium', 'Glucosamine', 'Chondroitin', 'MSM', 'TUDCA', 'Bile Salts', 'Digestive Enzymes', 'Pycnogenol', 'Vitamin B1 (Thiamine)', 'Benfotiamine', 'Niacin', 'Pantothenic Acid', 'Riboflavin',
		],
		'Sublingual'   => [ 'Nitroglycerin', 'Buprenorphine', 'Methylcobalamin (sublingual B12)' ],
		'Nasal'        => [ 'Fluticasone (Flonase)', 'Mometasone (Nasonex)', 'Budesonide nasal', 'Azelastine', 'Oxymetazoline (Afrin)', 'Saline nasal spray', 'Ipratropium nasal', 'Cromolyn nasal', 'Naloxone (Narcan)', 'Sumatriptan nasal', 'Esketamine (Spravato)', 'Calcitonin nasal' ],
		'Inhaled'      => [ 'Albuterol', 'Levalbuterol', 'Fluticasone inhaler', 'Budesonide-Formoterol (Symbicort)', 'Fluticasone-Salmeterol (Advair)', 'Tiotropium (Spiriva)', 'Beclomethasone (Qvar)', 'Ipratropium inhaler' ],
		'Topical'      => [ 'Minoxidil (topical)', 'Tretinoin', 'Adapalene', 'Benzoyl Peroxide', 'Clindamycin gel', 'Hydrocortisone cream', 'Triamcinolone cream', 'Clobetasol', 'Ketoconazole', 'Clotrimazole', 'Terbinafine cream', 'Mupirocin', 'Lidocaine', 'Diclofenac gel (Voltaren)', 'Testosterone gel', 'Estradiol gel', 'Progesterone cream', 'Azelaic Acid', 'Metronidazole gel', 'Tacrolimus ointment' ],
		'Transdermal'  => [ 'Estradiol patch', 'Nicotine patch', 'Lidocaine patch', 'Scopolamine patch', 'Clonidine patch', 'Fentanyl patch', 'Testosterone patch' ],
		'Eye drops'    => [ 'Artificial tears', 'Latanoprost', 'Timolol eye drops', 'Brimonidine', 'Ketotifen eye drops', 'Olopatadine eye drops', 'Prednisolone eye drops', 'Cyclosporine eye drops (Restasis)', 'Atropine eye drops' ],
		'Ear drops'    => [ 'Ciprofloxacin-Dexamethasone ear drops', 'Ofloxacin ear drops', 'Carbamide Peroxide ear drops' ],
		'Subcutaneous' => [ 'Insulin glargine', 'Insulin lispro', 'Insulin aspart', 'Insulin detemir', 'Insulin degludec', 'NPH insulin', 'Enoxaparin', 'Heparin', 'Adalimumab (Humira)', 'Etanercept', 'Dupilumab', 'Methotrexate injection', 'Epinephrine auto-injector', 'Denosumab', 'Evolocumab', 'Ozempic (semaglutide)', 'Wegovy (semaglutide)', 'Mounjaro (tirzepatide)', 'Zepbound (tirzepatide)', 'Saxenda (liraglutide)', 'Trulicity (dulaglutide)', 'Sumatriptan injection', 'Glucagon' ],
		'Intramuscular' => [ 'Vitamin B12 injection', 'Medroxyprogesterone injection (Depo-Provera)', 'Ketorolac', 'Ceftriaxone', 'Vitamin D injection', 'Lipotropic injection' ],
	];

	/** @return array<int,array{n:string,r:string}> Name + usual route, de-duplicated and sorted by name. */
	public static function all(): array {
		$seen = [];
		foreach ( self::BY_ROUTE as $route => $names ) {
			foreach ( $names as $name ) {
				$key = strtolower( preg_replace( '/[^a-z0-9]/i', '', $name ) );
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = [ 'n' => $name, 'r' => $route ];
				}
			}
		}
		$list = array_values( $seen );
		usort( $list, static function ( $a, $b ) {
			return strnatcasecmp( $a['n'], $b['n'] );
		} );
		return $list;
	}
}
