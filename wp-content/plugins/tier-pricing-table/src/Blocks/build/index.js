/******/ (function() { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./src/blocks/blocks/TieredPricingBlock.js":
/*!*************************************************!*\
  !*** ./src/blocks/blocks/TieredPricingBlock.js ***!
  \*************************************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ TieredPricingBlock; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);


function TieredPricingBlock({
  attributes,
  quantity,
  discount,
  price,
  isSelected
}) {
  let blockStyle = {
    padding: "0 10px",
    border: "1px solid #ccc",
    borderRadius: "5px",
    transition: "all .2s"
  };
  if (isSelected) {
    blockStyle.borderColor = attributes.activeTierColor;
    blockStyle.transform = "scale(1.06)";
  }
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-block",
    style: blockStyle
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-block__price",
    style: {
      fontWeight: 'bold',
      fontSize: '1.15em'
    }
  }, "$", price, ".00"), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "tiered-pricing-block__quantity"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", null, quantity), " ", (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('pieces', 'tier-pricing-table')));
}

/***/ }),

/***/ "./src/blocks/blocks/TieredPricingBlocks.js":
/*!**************************************************!*\
  !*** ./src/blocks/blocks/TieredPricingBlocks.js ***!
  \**************************************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ TieredPricingBlocks; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _tieredPricingRules_json__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./../../tieredPricingRules.json */ "./src/tieredPricingRules.json");
/* harmony import */ var _TieredPricingBlock__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./TieredPricingBlock */ "./src/blocks/blocks/TieredPricingBlock.js");



function TieredPricingBlocks({
  attributes
}) {
  const wrapperStyle = {
    display: "flex",
    flexWrap: "wrap",
    gap: "10px",
    margin: "15px 0"
  };
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    style: wrapperStyle
  }, _tieredPricingRules_json__WEBPACK_IMPORTED_MODULE_1__.map(rule => {
    return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_TieredPricingBlock__WEBPACK_IMPORTED_MODULE_2__["default"], {
      attributes: attributes,
      isSelected: rule.isSelected,
      key: rule.quantity,
      quantity: rule.quantity,
      discount: rule.discount,
      price: rule.price
    });
  }));
}

/***/ }),

/***/ "./src/blocks/dropdown/TieredPricingDropdown.js":
/*!******************************************************!*\
  !*** ./src/blocks/dropdown/TieredPricingDropdown.js ***!
  \******************************************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ TieredPricingDropdown; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _tieredPricingRules_json__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./../../tieredPricingRules.json */ "./src/tieredPricingRules.json");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__);



function TieredPricingDropdown({
  attributes
}) {
  const {
    quantity,
    price
  } = _tieredPricingRules_json__WEBPACK_IMPORTED_MODULE_1__[0];
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-dropdown"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-dropdown__select-box",
    style: {
      borderColor: attributes.activeTierColor
    }
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-dropdown-option"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-dropdown-option__quantity"
  }, quantity, " ", (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('pieces', 'tier-pricing-table')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-dropdown-option__pricing"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-option-price"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-option-price__discounted"
  }, "$", price, ".00")))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-dropdown__select-box-arrow"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("svg", {
    height: "24",
    viewBox: "0 0 48 48",
    width: "24",
    xmlns: "http://www.w3.org/2000/svg",
    style: {
      fill: attributes.activeTierColor
    }
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("path", {
    d: "M14.83 16.42l9.17 9.17 9.17-9.17 2.83 2.83-12 12-12-12z"
  }), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("path", {
    d: "M0-.75h48v48h-48z",
    fill: "none"
  })))));
}

/***/ }),

/***/ "./src/blocks/options/TieredPricingOption.js":
/*!***************************************************!*\
  !*** ./src/blocks/options/TieredPricingOption.js ***!
  \***************************************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ TieredPricingOption; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);


function TieredPricingOption({
  attributes,
  quantity,
  discount,
  price,
  isSelected
}) {
  let blockStyle = {};
  if (isSelected) {
    blockStyle.borderColor = attributes.activeTierColor;
    blockStyle.background = hexToRgbA(attributes.activeTierColor, 0.05);
  }
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: isSelected ? 'tiered-pricing-option tiered-pricing--active' : 'tiered-pricing-option',
    style: blockStyle
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-option__checkbox"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: isSelected ? 'tiered-pricing-option-checkbox tiered-pricing-option-checkbox--active' : 'tiered-pricing-option-checkbox'
  })), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-option__quantity"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("strong", null, quantity), " ", (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('pieces', 'tier-pricing-table')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-option__pricing"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-option-price"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-option-price__discounted"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "woocommerce-Price-amount amount"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "woocommerce-Price-currencySymbol"
  }, "$"), price, ".00"))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-option-total"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-option-total__label"
  }, "Total:"), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "tiered-pricing-option-total__discounted_total"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "woocommerce-Price-amount amount"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "woocommerce-Price-currencySymbol"
  }, "$"), price, ".00")))));
}
function hexToRgbA(hex, opacity = 1) {
  var c;
  if (/^#([A-Fa-f0-9]{3}){1,2}$/.test(hex)) {
    c = hex.substring(1).split('');
    if (c.length == 3) {
      c = [c[0], c[0], c[1], c[1], c[2], c[2]];
    }
    c = '0x' + c.join('');
    return 'rgba(' + [c >> 16 & 255, c >> 8 & 255, c & 255].join(',') + ',' + opacity + ')';
  }
  return '';
}

/***/ }),

/***/ "./src/blocks/options/TieredPricingOptions.js":
/*!****************************************************!*\
  !*** ./src/blocks/options/TieredPricingOptions.js ***!
  \****************************************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ TieredPricingOptions; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _tieredPricingRules_json__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./../../tieredPricingRules.json */ "./src/tieredPricingRules.json");
/* harmony import */ var _TieredPricingOption__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./TieredPricingOption */ "./src/blocks/options/TieredPricingOption.js");



function TieredPricingOptions({
  attributes
}) {
  const wrapperStyle = {};
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    style: wrapperStyle
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("style", {
    dangerouslySetInnerHTML: {
      __html: ['.tiered-pricing-option-checkbox--active:after {' + 'background:' + attributes.activeTierColor + '}\n' + '.tiered-pricing-option-checkbox--active {' + 'border-color:' + attributes.activeTierColor + '}\n'].join('\n')
    }
  }), _tieredPricingRules_json__WEBPACK_IMPORTED_MODULE_1__.map(rule => {
    return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_TieredPricingOption__WEBPACK_IMPORTED_MODULE_2__["default"], {
      attributes: attributes,
      isSelected: rule.isSelected,
      key: rule.quantity,
      quantity: rule.quantity,
      discount: rule.discount,
      price: rule.price
    });
  }));
}

/***/ }),

/***/ "./src/blocks/table/TieredPricingTable.js":
/*!************************************************!*\
  !*** ./src/blocks/table/TieredPricingTable.js ***!
  \************************************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ TieredPricingTable; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _TieredPricingTableHeader__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./TieredPricingTableHeader */ "./src/blocks/table/TieredPricingTableHeader.js");
/* harmony import */ var _TieredPricingTableRow__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./TieredPricingTableRow */ "./src/blocks/table/TieredPricingTableRow.js");
/* harmony import */ var _tieredPricingRules_json__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./../../tieredPricingRules.json */ "./src/tieredPricingRules.json");




function TieredPricingTable({
  attributes
}) {
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("table", {
    className: 'shop_table',
    style: {
      width: '100%',
      borderCollapse: 'collapse'
    }
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("thead", null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_TieredPricingTableHeader__WEBPACK_IMPORTED_MODULE_1__["default"], {
    quantityColumnLabel: attributes.quantityColumnTitle,
    discountColumnLabel: attributes.discountColumnTitle,
    priceColumnLabel: attributes.priceColumnTitle,
    attributes: attributes
  })), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("tbody", null, _tieredPricingRules_json__WEBPACK_IMPORTED_MODULE_3__.map(rule => {
    return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_TieredPricingTableRow__WEBPACK_IMPORTED_MODULE_2__["default"], {
      attributes: attributes,
      isSelected: rule.isSelected,
      key: rule.quantity,
      quantity: rule.quantity,
      discount: rule.discount,
      price: rule.price
    });
  })));
}

/***/ }),

/***/ "./src/blocks/table/TieredPricingTableHeader.js":
/*!******************************************************!*\
  !*** ./src/blocks/table/TieredPricingTableHeader.js ***!
  \******************************************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ TieredPricingTableHeader; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);

function TieredPricingTableHeader({
  attributes,
  quantityColumnLabel,
  discountColumnLabel,
  priceColumnLabel
}) {
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("tr", {
    style: {
      textAlign: "left"
    }
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("th", null, quantityColumnLabel), attributes.showDiscountColumn && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("th", null, discountColumnLabel), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("th", null, priceColumnLabel));
}

/***/ }),

/***/ "./src/blocks/table/TieredPricingTableRow.js":
/*!***************************************************!*\
  !*** ./src/blocks/table/TieredPricingTableRow.js ***!
  \***************************************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ TieredPricingTableRow; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);

function TieredPricingTableRow({
  attributes,
  quantity,
  discount,
  price,
  isSelected
}) {
  let tdStyles = {};
  if (isSelected) {
    tdStyles = {
      background: attributes.activeTierColor,
      color: "#fff"
    };
  }
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("tr", null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("td", {
    style: tdStyles
  }, quantity), attributes.showDiscountColumn && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("td", {
    style: tdStyles
  }, discount ? discount + '%' : '-'), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("td", {
    style: tdStyles
  }, "$", price, ".00"));
}

/***/ }),

/***/ "./src/edit.js":
/*!*********************!*\
  !*** ./src/edit.js ***!
  \*********************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ Edit; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_block_editor__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/block-editor */ "@wordpress/block-editor");
/* harmony import */ var _wordpress_block_editor__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_block_editor__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _editor_scss__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./editor.scss */ "./src/editor.scss");
/* harmony import */ var _blocks_table_TieredPricingTable__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./blocks/table/TieredPricingTable */ "./src/blocks/table/TieredPricingTable.js");
/* harmony import */ var _panels_ColumnsPanel_ColumnsPanel__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./panels/ColumnsPanel/ColumnsPanel */ "./src/panels/ColumnsPanel/ColumnsPanel.js");
/* harmony import */ var _blocks_blocks_TieredPricingBlocks__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./blocks/blocks/TieredPricingBlocks */ "./src/blocks/blocks/TieredPricingBlocks.js");
/* harmony import */ var _blocks_options_TieredPricingOptions__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./blocks/options/TieredPricingOptions */ "./src/blocks/options/TieredPricingOptions.js");
/* harmony import */ var _blocks_dropdown_TieredPricingDropdown__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./blocks/dropdown/TieredPricingDropdown */ "./src/blocks/dropdown/TieredPricingDropdown.js");
/* harmony import */ var _panels_MainOptionsPanel_DisplayTypePanel__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! ./panels/MainOptionsPanel/DisplayTypePanel */ "./src/panels/MainOptionsPanel/DisplayTypePanel.js");

/**
 * Retrieves the translation of text.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-i18n/
 */


/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * Those files can contain any CSS code that gets applied to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */








/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#edit
 *
 * @return {WPElement} Element to render.
 */
function Edit({
  attributes,
  className,
  setAttributes
}) {
  const blocks = {
    table: (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_blocks_table_TieredPricingTable__WEBPACK_IMPORTED_MODULE_3__["default"], {
      attributes: attributes
    }),
    blocks: (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_blocks_blocks_TieredPricingBlocks__WEBPACK_IMPORTED_MODULE_5__["default"], {
      attributes: attributes
    }),
    options: (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_blocks_options_TieredPricingOptions__WEBPACK_IMPORTED_MODULE_6__["default"], {
      attributes: attributes
    }),
    dropdown: (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_blocks_dropdown_TieredPricingDropdown__WEBPACK_IMPORTED_MODULE_7__["default"], {
      attributes: attributes
    })
  };
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    ...(0,_wordpress_block_editor__WEBPACK_IMPORTED_MODULE_1__.useBlockProps)()
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_block_editor__WEBPACK_IMPORTED_MODULE_1__.InspectorControls, {
    key: "setting",
    className: "tiered-pricing-table__block-settings"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_panels_MainOptionsPanel_DisplayTypePanel__WEBPACK_IMPORTED_MODULE_8__["default"], {
    attributes: attributes,
    setAttributes: setAttributes
  }), attributes.displayType === 'table' && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_panels_ColumnsPanel_ColumnsPanel__WEBPACK_IMPORTED_MODULE_4__["default"], {
    attributes: attributes,
    setAttributes: setAttributes
  })), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h3", {
    style: {
      marginBottom: ".5em"
    }
  }, attributes.title), blocks[attributes.displayType] ? blocks[attributes.displayType] : blocks['table']);
}

/***/ }),

/***/ "./src/index.js":
/*!**********************!*\
  !*** ./src/index.js ***!
  \**********************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _wordpress_blocks__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/blocks */ "@wordpress/blocks");
/* harmony import */ var _wordpress_blocks__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_blocks__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _style_scss__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./style.scss */ "./src/style.scss");
/* harmony import */ var _edit__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./edit */ "./src/edit.js");
/* harmony import */ var _block_json__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./block.json */ "./src/block.json");
/**
 * Registers a new block provided a unique name and an object defining its behavior.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-registration/
 */


/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * All files containing `style` keyword are bundled together. The code used
 * gets applied both to the front of your site and to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */


/**
 * Internal dependencies
 */



/**
 * Every block starts by registering a new block type definition.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-registration/
 */
(0,_wordpress_blocks__WEBPACK_IMPORTED_MODULE_0__.registerBlockType)(_block_json__WEBPACK_IMPORTED_MODULE_3__.name, {
  /**
   * @see ./edit.js
   */
  edit: _edit__WEBPACK_IMPORTED_MODULE_2__["default"]
});

/***/ }),

/***/ "./src/options/CheckboxOption.js":
/*!***************************************!*\
  !*** ./src/options/CheckboxOption.js ***!
  \***************************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ CheckboxOption; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__);



function CheckboxOption({
  attributeName,
  label,
  help,
  attributes,
  setAttributes,
  onChange
}) {
  const [isChecked, setIsChecked] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(attributes[attributeName]);
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.BaseControl, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.ToggleControl, {
    label: label,
    help: help,
    checked: isChecked,
    onChange: isChecked => {
      let _attributes = [];
      _attributes[attributeName] = isChecked;
      setAttributes(_attributes);
      setIsChecked(isChecked);
      onChange && onChange(isChecked);
    }
  }));
}

/***/ }),

/***/ "./src/options/ColorPicker.js":
/*!************************************!*\
  !*** ./src/options/ColorPicker.js ***!
  \************************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ ColorPicker; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__);



function ColorPicker({
  attributes,
  setAttributes,
  attributeName,
  label,
  help,
  onChange,
  clearable,
  colors
}) {
  const [color, setColor] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(attributes[attributeName]);
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.BaseControl, {
    label: label,
    help: help,
    className: "tiered-pricing-table__base-control"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.ColorPalette, {
    style: {
      width: '100%'
    },
    value: color,
    colors: colors,
    clearable: clearable,
    onChange: color => {
      let _attributes = [];
      _attributes[attributeName] = color;
      setAttributes(_attributes);
      setColor(color);
      onChange && onChange(color);
    }
  }));
}

/***/ }),

/***/ "./src/options/InputOption.js":
/*!************************************!*\
  !*** ./src/options/InputOption.js ***!
  \************************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ InputOption; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__);



function InputOption({
  attributeName,
  label,
  help,
  attributes,
  setAttributes,
  onChange
}) {
  const [value, setValue] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(attributes[attributeName]);
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.BaseControl, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.TextControl, {
    label: label,
    value: value,
    onChange: nextValue => {
      let _attributes = [];
      _attributes[attributeName] = nextValue;
      setAttributes(_attributes);
      setValue(nextValue);
      onChange && onChange(nextValue);
    }
  }));
}

/***/ }),

/***/ "./src/panels/ColumnsPanel/ColumnsPanel.js":
/*!*************************************************!*\
  !*** ./src/panels/ColumnsPanel/ColumnsPanel.js ***!
  \*************************************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ ColumnsPanel; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _options_CheckboxOption__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../../options/CheckboxOption */ "./src/options/CheckboxOption.js");
/* harmony import */ var _options_InputOption__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../../options/InputOption */ "./src/options/InputOption.js");





function ColumnsPanel({
  attributes,
  setAttributes
}) {
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Panel, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.PanelBody, {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Columns', 'tier-pricing-table'),
    initialOpen: true
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.PanelRow, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_options_InputOption__WEBPACK_IMPORTED_MODULE_4__["default"], {
    attributes: attributes,
    setAttributes: setAttributes,
    attributeName: "quantityColumnTitle",
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)("Quantity column title", 'tier-pricing-table')
  })), attributes.showDiscountColumn && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.PanelRow, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_options_InputOption__WEBPACK_IMPORTED_MODULE_4__["default"], {
    attributes: attributes,
    setAttributes: setAttributes,
    attributeName: "discountColumnTitle",
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)("Discount column title", 'tier-pricing-table')
  })), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.PanelRow, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_options_InputOption__WEBPACK_IMPORTED_MODULE_4__["default"], {
    attributes: attributes,
    setAttributes: setAttributes,
    attributeName: "priceColumnTitle",
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)("Price column title", 'tier-pricing-table')
  })), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.PanelRow, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_options_CheckboxOption__WEBPACK_IMPORTED_MODULE_3__["default"], {
    attributes: attributes,
    setAttributes: setAttributes,
    help: attributes.showDiscountColumn ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)("Discount column is showing.", 'tier-pricing-table') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)("Discount column is not showing.", 'tier-pricing-table'),
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)("Show percentage discount", 'tier-pricing-table'),
    attributeName: "showDiscountColumn"
  }))));
}

/***/ }),

/***/ "./src/panels/MainOptionsPanel/DisplayTypePanel.js":
/*!*********************************************************!*\
  !*** ./src/panels/MainOptionsPanel/DisplayTypePanel.js ***!
  \*********************************************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": function() { return /* binding */ MainOptionsPanel; }
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_3__);
/* harmony import */ var _options_InputOption__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../../options/InputOption */ "./src/options/InputOption.js");
/* harmony import */ var _options_ColorPicker__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ../../options/ColorPicker */ "./src/options/ColorPicker.js");






function MainOptionsPanel({
  attributes,
  setAttributes
}) {
  const types = [{
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Table', 'tier-pricing-table'),
    value: "table"
  }, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Blocks', 'tier-pricing-table'),
    value: "blocks"
  }, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Options', 'tier-pricing-table'),
    value: "options"
  }, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Dropdown', 'tier-pricing-table'),
    value: "dropdown"
  }];
  const [displayType, setDisplayType] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_3__.useState)(attributes.displayType);
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Panel, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.PanelBody, {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Main settings', 'tier-pricing-table'),
    initialOpen: true
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.PanelRow, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.SelectControl, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Display type', 'tier-pricing-table'),
    value: displayType,
    options: types,
    onChange: displayType => {
      setAttributes({
        displayType: displayType
      });
      setDisplayType(displayType);
    }
  })), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.PanelRow, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_options_InputOption__WEBPACK_IMPORTED_MODULE_4__["default"], {
    attributes: attributes,
    setAttributes: setAttributes,
    attributeName: "title",
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)("Title", 'tier-pricing-table')
  })), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.PanelRow, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_options_ColorPicker__WEBPACK_IMPORTED_MODULE_5__["default"], {
    attributes: attributes,
    colors: [{
      name: 'default',
      color: '#96598A'
    }, {
      name: 'white',
      color: '#fff'
    }, {
      name: 'black',
      color: '#000'
    }, {
      name: 'woocommerce',
      color: '#8055B3'
    }],
    attributeName: "activeTierColor",
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)("Active tier color"),
    setAttributes: setAttributes,
    clearable: false
  }))));
}

/***/ }),

/***/ "./src/editor.scss":
/*!*************************!*\
  !*** ./src/editor.scss ***!
  \*************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ }),

/***/ "./src/style.scss":
/*!************************!*\
  !*** ./src/style.scss ***!
  \************************/
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ }),

/***/ "react":
/*!************************!*\
  !*** external "React" ***!
  \************************/
/***/ (function(module) {

module.exports = window["React"];

/***/ }),

/***/ "@wordpress/block-editor":
/*!*************************************!*\
  !*** external ["wp","blockEditor"] ***!
  \*************************************/
/***/ (function(module) {

module.exports = window["wp"]["blockEditor"];

/***/ }),

/***/ "@wordpress/blocks":
/*!********************************!*\
  !*** external ["wp","blocks"] ***!
  \********************************/
/***/ (function(module) {

module.exports = window["wp"]["blocks"];

/***/ }),

/***/ "@wordpress/components":
/*!************************************!*\
  !*** external ["wp","components"] ***!
  \************************************/
/***/ (function(module) {

module.exports = window["wp"]["components"];

/***/ }),

/***/ "@wordpress/element":
/*!*********************************!*\
  !*** external ["wp","element"] ***!
  \*********************************/
/***/ (function(module) {

module.exports = window["wp"]["element"];

/***/ }),

/***/ "@wordpress/i18n":
/*!******************************!*\
  !*** external ["wp","i18n"] ***!
  \******************************/
/***/ (function(module) {

module.exports = window["wp"]["i18n"];

/***/ }),

/***/ "./src/block.json":
/*!************************!*\
  !*** ./src/block.json ***!
  \************************/
/***/ (function(module) {

module.exports = JSON.parse('{"$schema":"https://schemas.wp.org/trunk/block.json","apiVersion":3,"name":"tiered-pricing-table/tiered-pricing-block","version":"1.0.0","title":"Tiered Pricing","category":"woocommerce","icon":"tag","description":"Tiered pricing widget. Must be used on the product page template. Find more options to customize in the plugin settings.","example":{},"supports":{"html":false,"className":false,"customClassName":false,"reusable":false},"attributes":{"displayType":{"type":"string","default":"table"},"title":{"type":"string","default":"Buy more, save more!"},"activeTierColor":{"type":"string","default":"#96598A"},"showDiscountColumn":{"type":"boolean","default":true},"quantityColumnTitle":{"type":"string","default":"Quantity"},"discountColumnTitle":{"type":"string","default":"Discount"},"priceColumnTitle":{"type":"string","default":"Price"}},"textdomain":"tier-pricing-table","editorScript":"file:./index.js","editorStyle":"file:./index.css"}');

/***/ }),

/***/ "./src/tieredPricingRules.json":
/*!*************************************!*\
  !*** ./src/tieredPricingRules.json ***!
  \*************************************/
/***/ (function(module) {

module.exports = JSON.parse('[{"quantity":"5 - 9","discount":null,"price":100,"isSelected":false,"totalPrice":500},{"quantity":"10 - 49","discount":20,"price":80,"isSelected":false,"totalPrice":800},{"quantity":"50 - 99","discount":30,"price":70,"isSelected":true,"totalPrice":3500},{"quantity":"100+","discount":40,"price":60,"isSelected":false,"totalPrice":6000}]');

/***/ })

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = __webpack_modules__;
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/chunk loaded */
/******/ 	!function() {
/******/ 		var deferred = [];
/******/ 		__webpack_require__.O = function(result, chunkIds, fn, priority) {
/******/ 			if(chunkIds) {
/******/ 				priority = priority || 0;
/******/ 				for(var i = deferred.length; i > 0 && deferred[i - 1][2] > priority; i--) deferred[i] = deferred[i - 1];
/******/ 				deferred[i] = [chunkIds, fn, priority];
/******/ 				return;
/******/ 			}
/******/ 			var notFulfilled = Infinity;
/******/ 			for (var i = 0; i < deferred.length; i++) {
/******/ 				var chunkIds = deferred[i][0];
/******/ 				var fn = deferred[i][1];
/******/ 				var priority = deferred[i][2];
/******/ 				var fulfilled = true;
/******/ 				for (var j = 0; j < chunkIds.length; j++) {
/******/ 					if ((priority & 1 === 0 || notFulfilled >= priority) && Object.keys(__webpack_require__.O).every(function(key) { return __webpack_require__.O[key](chunkIds[j]); })) {
/******/ 						chunkIds.splice(j--, 1);
/******/ 					} else {
/******/ 						fulfilled = false;
/******/ 						if(priority < notFulfilled) notFulfilled = priority;
/******/ 					}
/******/ 				}
/******/ 				if(fulfilled) {
/******/ 					deferred.splice(i--, 1)
/******/ 					var r = fn();
/******/ 					if (r !== undefined) result = r;
/******/ 				}
/******/ 			}
/******/ 			return result;
/******/ 		};
/******/ 	}();
/******/ 	
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	!function() {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = function(module) {
/******/ 			var getter = module && module.__esModule ?
/******/ 				function() { return module['default']; } :
/******/ 				function() { return module; };
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	}();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	!function() {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = function(exports, definition) {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	}();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	!function() {
/******/ 		__webpack_require__.o = function(obj, prop) { return Object.prototype.hasOwnProperty.call(obj, prop); }
/******/ 	}();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	!function() {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = function(exports) {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	}();
/******/ 	
/******/ 	/* webpack/runtime/jsonp chunk loading */
/******/ 	!function() {
/******/ 		// no baseURI
/******/ 		
/******/ 		// object to store loaded and loading chunks
/******/ 		// undefined = chunk not loaded, null = chunk preloaded/prefetched
/******/ 		// [resolve, reject, Promise] = chunk loading, 0 = chunk loaded
/******/ 		var installedChunks = {
/******/ 			"index": 0,
/******/ 			"./style-index": 0
/******/ 		};
/******/ 		
/******/ 		// no chunk on demand loading
/******/ 		
/******/ 		// no prefetching
/******/ 		
/******/ 		// no preloaded
/******/ 		
/******/ 		// no HMR
/******/ 		
/******/ 		// no HMR manifest
/******/ 		
/******/ 		__webpack_require__.O.j = function(chunkId) { return installedChunks[chunkId] === 0; };
/******/ 		
/******/ 		// install a JSONP callback for chunk loading
/******/ 		var webpackJsonpCallback = function(parentChunkLoadingFunction, data) {
/******/ 			var chunkIds = data[0];
/******/ 			var moreModules = data[1];
/******/ 			var runtime = data[2];
/******/ 			// add "moreModules" to the modules object,
/******/ 			// then flag all "chunkIds" as loaded and fire callback
/******/ 			var moduleId, chunkId, i = 0;
/******/ 			if(chunkIds.some(function(id) { return installedChunks[id] !== 0; })) {
/******/ 				for(moduleId in moreModules) {
/******/ 					if(__webpack_require__.o(moreModules, moduleId)) {
/******/ 						__webpack_require__.m[moduleId] = moreModules[moduleId];
/******/ 					}
/******/ 				}
/******/ 				if(runtime) var result = runtime(__webpack_require__);
/******/ 			}
/******/ 			if(parentChunkLoadingFunction) parentChunkLoadingFunction(data);
/******/ 			for(;i < chunkIds.length; i++) {
/******/ 				chunkId = chunkIds[i];
/******/ 				if(__webpack_require__.o(installedChunks, chunkId) && installedChunks[chunkId]) {
/******/ 					installedChunks[chunkId][0]();
/******/ 				}
/******/ 				installedChunks[chunkId] = 0;
/******/ 			}
/******/ 			return __webpack_require__.O(result);
/******/ 		}
/******/ 		
/******/ 		var chunkLoadingGlobal = self["webpackChunktiered_pricing_block"] = self["webpackChunktiered_pricing_block"] || [];
/******/ 		chunkLoadingGlobal.forEach(webpackJsonpCallback.bind(null, 0));
/******/ 		chunkLoadingGlobal.push = webpackJsonpCallback.bind(null, chunkLoadingGlobal.push.bind(chunkLoadingGlobal));
/******/ 	}();
/******/ 	
/************************************************************************/
/******/ 	
/******/ 	// startup
/******/ 	// Load entry module and return exports
/******/ 	// This entry module depends on other loaded chunks and execution need to be delayed
/******/ 	var __webpack_exports__ = __webpack_require__.O(undefined, ["./style-index"], function() { return __webpack_require__("./src/index.js"); })
/******/ 	__webpack_exports__ = __webpack_require__.O(__webpack_exports__);
/******/ 	
/******/ })()
;
//# sourceMappingURL=index.js.map