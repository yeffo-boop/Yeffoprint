/* global wcShippingSiftConfig */
var _sift = ( window._sift = window._sift || [] );
_sift.push( [ '_setAccount', wcShippingSiftConfig.beacon_key ] );
_sift.push( [ '_setUserId', wcShippingSiftConfig.user_id ] );
_sift.push( [ '_trackPageview' ] );
