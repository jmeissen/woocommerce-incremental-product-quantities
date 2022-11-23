<?php
/**
 *  Given the product, this will check which rule is being applied to a product
 *  If there is a rule, the values will be returned otherwise it is inactive
 *  or overridden (from the product meta box).
 *
 *  @param object $product WC_Product object.
 *  @param string $role User role to get rule from, otherwise current user role is used.
 *  @return mixed  String of rule status / Object top rule post
 */
function wpbo_get_applied_rule( $product, $role = null ) {

	// Check for site wide rule.
	$options = get_option( 'ipq_options' );

	if ( get_post_meta( $product->get_id(), '_wpbo_deactive', true ) == 'on' ) {
		return 'inactive';

	} elseif ( get_post_meta( $product->get_id(), '_wpbo_override', true ) == 'on' ) {
		return 'override';

	} elseif ( isset( $options['ipq_site_rule_active'] ) && $options['ipq_site_rule_active'] == 'on' ) {
		return 'sitewide';

	} else {
		return wpbo_get_applied_rule_obj( $product, $role );
	}
}

/**
 *  Get the Rule Object thats being applied to a given product.
 *  Will return null if no rule is applied.
 *
 *  @param  object $product WC_Product object.
 *  @param  string $role  User role to get rule from, otherwise current user role is used.
 *  @return mixed  Null if no rule applies / Object top rule post
 */
function wpbo_get_applied_rule_obj( $product, $role = null ) {

	// Get role if not passed.
	if ( ! is_user_logged_in() || ( null === $role && null === ( $role = array_pop( get_userdata( get_current_user_id() )->roles ) ) ) ) {
		$role = 'guest';
	}

	// Check for rule / role transient.
	if ( false === ( $rules = get_transient( 'ipq_rules_' . $role ) ) ) {

		// Filter applicable rules by role.
		$rules = array_filter(
			get_posts(
				array(
					'posts_per_page' => -1,
					'post_type'      => 'quantity-rule',
					'post_status'    => 'publish',
				)
			),
			function( $rule ) use ( $role ) {
				return in_array( $role, get_post_meta( $rule->ID, '_roles' )[0], true );
			}
		);

		$duration = 60 * 60 * 12; // 12 hours
		set_transient( 'ipq_rules_' . $role, $rules, $duration );
	}

	$top      = null;
	$top_rule = null;

	// Loop through the rules and find the ones that apply.
	foreach ( $rules as $rule ) {

		// Get the Rule's Cats and Tags.
		$cats = get_post_meta( $rule->ID, '_cats' );
		if ( $cats ) {
			$cats = $cats[0];
		}

		$tags = get_post_meta( $rule->ID, '_tags' );
		if ( $tags ) {
			$tags = $tags[0];
		}

		$rule_taxes = array_merge( $tags, $cats );

		// Get product terms.
		$product_terms = array_merge(
			$product->get_category_ids(),
			$product->get_tag_ids()
		);

		// If the arrays intersect, apply rule based on priority.
		if ( array_intersect( $rule_taxes, $product_terms ) ) {
			$priority = get_post_meta( $rule->ID, '_priority', true );

			if ( $priority !== '' && $top > $priority || $top === null ) {
				$top      = $priority;
				$top_rule = $rule;
			}
		}
	}

	return $top_rule;
}

/**
 *  Get the Input Value (min/max/step/priority/role/all) for a product given a rule
 *
 *  @params string  $type Product type
 *  @params object  $produt Product Object
 *  @params object  $rule Quantity Rule post object
 *  @return void
 */
function wpbo_get_value_from_rule( $type, $product, $rule ) {

	// Validate $type
	if ( $type != 'min' and
		 $type != 'max' and
		 $type != 'step' and
		 $type != 'all' and
		 $type != 'priority' and
		 $type != 'role' and
		 $type != 'min_oos' and
		 $type != 'max_oos'
		) {
		return null;

		// Validate for missing rule
	} elseif ( $rule == null ) {
		return null;

		// Return Null if Inactive
	} elseif ( $rule == 'inactive' ) {
		return null;

		// Return Product Meta if Override is on
	} elseif ( $rule == 'override' ) {

		// Check if the product is out of stock
		$stock = $product->get_stock_quantity();

		// Check if the product is under stock management and out of stock
		if ( strlen( $stock ) != 0 and $stock <= 0 ) {

			// Return Out of Stock values if they exist
			switch ( $type ) {
				case 'min':
					$min_oos = get_post_meta( $product->get_id(), '_wpbo_minimum_oos', true );
					if ( $min_oos != '' ) {
						return $min_oos;
					}
					break;

				case 'max':
					$max_oos = get_post_meta( $product->get_id(), '_wpbo_maximum_oos', true );
					if ( $max_oos != '' ) {
						return $max_oos;
					}
					break;
			}
			// If nothing was returned, proceed as usual
		}

		switch ( $type ) {
			case 'all':
				return array(
					'min_value' => get_post_meta( $product->get_id(), '_wpbo_minimum', true ),
					'max_value' => get_post_meta( $product->get_id(), '_wpbo_maximum', true ),
					'step'      => get_post_meta( $product->get_id(), '_wpbo_step', true ),
					'min_oos'   => get_post_meta( $product->get_id(), '_wpbo_minimum_oos', true ),
					'max_oos'   => get_post_meta( $product->get_id(), '_wpbo_maximum_oos', true ),
				);
				break;
			case 'min':
				return get_post_meta( $product->get_id(), '_wpbo_minimum', true );
				break;

			case 'max':
				return get_post_meta( $product->get_id(), '_wpbo_maximum', true );
				break;

			case 'step':
				return get_post_meta( $product->get_id(), '_wpbo_step', true );
				break;

			case 'min_oos':
				return get_post_meta( $product->get_id(), '_wpbo_minimum_oos', true );
				break;

			case 'max_oos':
				return get_post_meta( $product->get_id(), '_wpbo_maximum_oos', true );
				break;

			case 'priority':
				return null;
				break;
		}

		// Check for Site Wide Rule
	} elseif ( $rule == 'sitewide' ) {

		$options = get_option( 'ipq_options' );

		if ( isset( $options['ipq_site_min'] ) ) {
			$min = $options['ipq_site_min'];
		} else {
			$min = '';
		}

		if ( isset( $options['ipq_site_max'] ) ) {
			$max = $options['ipq_site_max'];
		} else {
			$max = '';
		}

		if ( isset( $options['ipq_site_min_oos'] ) ) {
			$min_oos = $options['ipq_site_min_oos'];
		} else {
			$min_oos = '';
		}

		if ( isset( $options['ipq_site_max_oos'] ) ) {
			$max_oos = $options['ipq_site_max_oos'];
		} else {
			$max_oos = '';
		}

		if ( isset( $options['ipq_site_step'] ) ) {
			$step = $options['ipq_site_step'];
		} else {
			$step = '';
		}

		switch ( $type ) {
			case 'all':
				return array(
					'min_value' => $min,
					'max_value' => $max,
					'min_oos'   => $min_oos,
					'max_oos'   => $max_oos,
					'step'      => $step,
				);
				break;

			case 'min':
				return array( 'min' => $min );
				break;

			case 'max':
				return array( 'max' => $max );
				break;

			case 'min_oos':
				return array( 'min_oos' => $min_oos );
				break;

			case 'max_oos':
				return array( 'max_oos' => $max_oos );
				break;

			case 'step':
				return array( 'step' => $step );
				break;

			case 'priority':
				return null;
				break;

		}

		// Return Values from the Rule based on $type requested
	} else {

		switch ( $type ) {
			case 'all':
				return array(
					'min_value' => get_post_meta( $rule->ID, '_min', true ),
					'max_value' => get_post_meta( $rule->ID, '_max', true ),
					'min_oos'   => get_post_meta( $rule->ID, '_min_oos', true ),
					'max_oos'   => get_post_meta( $rule->ID, '_max_oos', true ),
					'step'      => get_post_meta( $rule->ID, '_step', true ),
					'priority'  => get_post_meta( $rule->ID, '_priority', true ),
					'roles'     => get_post_meta( $rule->ID, '_roles', true ),
				);
				break;

			case 'min':
				return get_post_meta( $rule->ID, '_min', true );
				break;

			case 'max':
				return get_post_meta( $rule->ID, '_max', true );
				break;

			case 'min_oos':
				return get_post_meta( $rule->ID, '_min_oos', true );
				break;

			case 'max_oos':
				return get_post_meta( $rule->ID, '_max_oos', true );
				break;

			case 'step':
				return get_post_meta( $rule->ID, '_step', true );
				break;

			case 'role':
				return get_post_meta( $rule->ID, '_roles', true );
				break;

			case 'priority':
				return get_post_meta( $rule->ID, '_priority', true );
				break;
		}
	}
}

/**
 * Validate inputs as numbers and set them to null if 0
 *
 * @param string $number Inputted to be validated.
 *
 * @return integer|null
 */
function wpbo_validate_number( $number ) {

	$number = stripslashes( $number );
	$number = intval( $number );

	if ( $number == 0 ) {
		return null;
	} elseif ( $number < 0 ) {
		return null;
	}

	return $number;
}
