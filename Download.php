<?php
// +----------------------------------------------------------------------+
// | PEAR :: HTTP :: Download                                             |
// +----------------------------------------------------------------------+
// | This source file is subject to version 3.0 of the PHP license,       |
// | that is available at http://www.php.net/license/3_0.txt              |
// | If you did not receive a copy of the PHP license and are unable      |
// | to obtain it through the world-wide-web, please send a note to       |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003 Michael Wallner <mike@iworks.at>                  |
// +----------------------------------------------------------------------+
//
// $Id$

/**
* Manage HTTP Downloads.
* 
* @author       Michael Wallner <mike@php.net>
* @package      HTTP_Download
* @category     HTTP
*/

/**
* Requires PEAR
*/
require_once 'PEAR.php';

/**
* Requires HTTP_Header
*/
require_once('HTTP/Header.php');

/**
* To set with HTTP_Download::setContentDisposition():
* Send data as attachment
*/
define('HTTP_DOWNLOAD_ATTACHMENT', 'attachment');

/**
* To set with HTTP_Download::setContentDisposition():
* Send data inline
*/
define('HTTP_DOWNLOAD_INLINE', 'inline');

/**#@+
* Error constants
*/
define('HTTP_DOWNLOAD_E_HEADERS_SENT',          -1);
define('HTTP_DOWNLOAD_E_NO_EXT_ZLIB',           -2);
define('HTTP_DOWNLOAD_E_NO_EXT_MMAGIC',         -3);
define('HTTP_DOWNLOAD_E_INVALID_FILE',          -4);
define('HTTP_DOWNLOAD_E_INVALID_PARAM',         -5);
define('HTTP_DOWNLOAD_E_INVALID_RESOURCE',      -6);
define('HTTP_DOWNLOAD_E_INVALID_REQUEST',       -7);
define('HTTP_DOWNLOAD_E_INVALID_CONTENT_TYPE',  -8);
/**#@+*/

/** 
* Send HTTP Downloads.
*
* With this package you can handle (hidden) downloads.
* It supports partial downloads, resuming and sending 
* raw data ie. from database BLOBs.
* 
* <i>ATTENTION:</i>
* You shouldn't use this package together with ob_gzhandler or 
* zlib.output_compression enabled in your php.ini, especially 
* if you want to send already gzipped data!
* 
* <kbd><u>
* Usage Example 1:
* </u></kbd>
* <code>
* 
* $params = array(
*   'file'                  => '../hidden/download.tgz',
*   'contenttype'           => 'application/x-gzip',
*   'contentdisposition'    => array(HTTP_DOWNLOAD_ATTACHMENT, 'latest.tgz'),
* );
* 
* $error = HTTP_Download::staticSend($params, false);
* 
* </code>
* 
* 
* <kbd><u>
* Usage Example 2:
* </u></kbd>
* <code>
* 
* $dl = &new HTTP_Download();
* $dl->setFile('../hidden/download.tgz');
* $dl->setContentDisposition(HTTP_DOWNLOAD_ATTACHMENT, 'latest.tgz');
* // with ext/magic.mime
* // $dl->guessContentType();
* // else:
* $dl->setContentType('application/x-gzip');
* $dl->send();
* 
* </code>
* 
* 
* <kbd><u>
* Usage Example 3:
* </u></kbd>
* <code>
* 
* $dl = &new HTTP_Download();
* $dl->setData($blob_from_db);
* $dl->setLastModified($unix_timestamp);
* $dl->setContentType('application/x-gzip');
* $dl->setContentDisposition(HTTP_DOWNLOAD_ATTACHMENT, 'latest.tgz');
* $dl->send();
* 
* </code>
* 
* @author   Michael Wallner <mike@php.net>
* @version  $Revision$
* @access   public
*/
class HTTP_Download extends HTTP_Header
{

    /**
    * Path to file for download
    *
    * @see      HTTP_Download::setFile()
    * @access   private
    * @var      string
    */
    var $_file = '';
    
    /**
    * Data for download
    *
    * @see      HTTP_Download::setData()
    * @access   private
    * @var      string
    */
    var $_data = null;
    
    /**
    * Resource handle for download
    *
    * @see      HTTP_Download::setResource()
    * @access   private
    * @var      int
    */
    var $_handle = null;
    
    /**
    * Whether to gzip the download
    *
    * @access   private
    * @var      bool
    */
    var $_gzip = false;
    
    /**
    * Size of download
    *
    * @access   private
    * @var      int
    */
    var $_size = 0;
    
    /**
    * Last modified (GMT)
    *
    * @access   private
    * @var      string
    */
    var $_last_modified = '';
    
    /**
    * HTTP headers
    *
    * @access   private
    * @var      array
    */
    var $_headers   = array(
        'Content-Type'  => 'application/x-octetstream',
        'Cache-Control' => 'public',
        'Accept-Ranges' => 'bytes',
        'Connection'    => 'close'
    );
    
	/**
    * Constructor
    *
    * Set supplied parameters.
    * 
    * @access   public
    * @param    array   $params     associative array of parameters
    * 
    *           <b>one of:</b>
    *                   o 'file'                => path to file for download
    *                   o 'data'                => raw data for download
    *                   o 'resource'            => resource handle for download
    * <br/>
    *           <b>and any of:</b>
    *                   o 'gzip'                => whether to gzip the download
    *                   o 'lastmodified'        => unix timestamp
    *                   o 'contenttype'         => content type of download
    *                   o 'contentdisposition'  => content disposition
    * 
    * <br />
    * 'Content-Disposition' is not HTTP compliant, but most browsers 
    * follow this header, so it was borrowed from MIME standard.
    * 
    * It looks like this: 
    * "Content-Disposition: attachment; filename=example.tgz".
    * 
    * @see HTTP_Download::setContentDisposition()
    */
    function HTTP_Download($params = array())
    {
        $this->setParams($params);
    }
    
    /**
    * Set parameters
    *
    * @throws   PEAR_Error
    * @access   public
    * @return   mixed   true on success or PEAR_Error
    * @param    array   $params     associative array of parameters
    * 
    * @see      HTTP_Download::HTTP_Download()
    */
    function setParams($params)
    {
        foreach($params as $param => $value){
            if (!method_exists($this, 'set' . $param)) {
                return PEAR::raiseError(
                    "Method 'set$param' doesn't exist.",
                    HTTP_DOWNLOAD_E_INVALID_PARAM
                );
            }
            if (strToLower($param) == 'contentdisposition') {
                if (is_array($value)) {
                    $disp   = $value[0];
                    $fname  = @$value[1];
                } else {
                    $disp   = $value;
                    $fname  = null;
                }
                $e = $this->setContentDisposition($disp, $fname);
            } else {
                $e = $this->{'set' . $param}($value);
            }
            if (PEAR::isError($e)) {
                return $e;
            }
        }
        return true;
    }
    
    /**
    * Set path to file for download
    *
    * @throws   PEAR_Error
    * @access   public
    * @return   mixed   true on success or PEAR_Error
    * @param    string  $file       path to file for download
    * @param    bool    $send_404   whether to send HTTP/404 if
    *                               the file wasn't found
    */
    function setFile($file, $send_404 = true)
    {
        $file = realpath($file);
        if (!is_file($file)) {
            if ($send_404) {
                $this->sendStatusCode(404);
            }
            return PEAR::raiseError(
                "File '$file' not found.",
                HTTP_DOWNLOAD_E_INVALID_FILE
            );
        }
        $this->setLastModified(filemtime($file));
        $this->_file = $file;
        $this->_size = filesize($file);
        return true;
    }
    
    /**
    * Set data for download
    *
    * Set <var>$data</var> to null if you want to unset this.
    * 
    * @access   public
    * @return   void
    * @param    $data   raw data to send
    */
    function setData($data = null)
    {
        $this->_data = $data;
        $this->_size = strlen($data);
    }
    
    /**
    * Set resource for download
    *
    * The resource handle supplied will be closed after sending the download.
    * 
    * Returns a PEAR_Error if <var>$handle</var> is no valid resource.
    * 
    * @throws   PEAR_Error
    * @access   public
    * @return   mixed   true on success or PEAR_Error
    * @param    int     $handle     resource handle
    */
    function setResource($handle = null)
    {
        // Check if $handle is a valid resource
        if (!is_resource($handle)) {
            if (!is_null($handle)) {
                return PEAR::raiseError(
                    "Handle '$handle' is no valid resource.",
                    HTTP_DOWNLOAD_E_INVALID_RESOURCE
                );
            } else {
                $this->_handle  = null;
                $this->_size    = 0;
            }
        } else {
            $this->_handle  = $handle;
            $stats          = fstat($handle);
            $this->_size    = $stats['size'];
        }
        return true;
    }
    
    /**
    * Whether to gzip the download
    *
    * Returns a PEAR_Error if ext/zlib is not available
    * 
    * @throws   PEAR_Error
    * @access   public
    * @return   mixed   true on success or PEAR_Error
    * @param    bool    $gzip   whether to gzip the download
    */
    function setGzip($gzip = false)
    {
        if ($gzip && !extension_loaded('zlib') && !PEAR::loadExtension('zlib')){
            return PEAR::raiseError(
                'Compression (ext/zlib) not available.',
                HTTP_DOWNLOAD_E_NO_EXT_ZLIB
            );
        }
        $this->_gzip = (bool) $gzip;
        return true;
    }

    /**
    * Set "Last-Modified"
    *
    * This is usually determined by filemtime($file) in HTTP_Download::setFile()
    * If you set raw data for download with HTTP_Download::setData() and you
    * want do send an appropiate "Last-Modified" header, you should call this
    * method.
    * 
    * @access   public
    * @return   void
    * @param    int     unix timestamp
    */
    function setLastModified($last_modified)
    {
        $this->_last_modified            = HTTP::Date((int) $last_modified);
        $this->_headers['Last-Modified'] = $this->_last_modified;
    }
    
    /**
    * Set Content-Disposition header
    * 
    * @see HTTP_Download::HTTP_Download
    *
    * @access   public
    * @return   void
    * @param    string  $disposition    whether to send the download
    *                                   inline or as attachment
    * @param    string  $file_name      the filename to display in
    *                                   the browser's download window
    * 
    * <b>Example:</b>
    * <code>
    * $HTTP_Download->setContentDisposition(
    *   HTTP_DOWNLOAD_ATTACHMENT,
    *   'download.tgz'
    * );
    * </code>
    */
    function setContentDisposition(
        $disposition = HTTP_DOWNLOAD_ATTACHMENT, 
        $file_name = null
    )
    {
        $cd = $disposition;
        if (!is_null($file_name)) {
            $cd .= '; filename="' . $file_name . '"';
        } elseif ($this->_file) {
            $cd .= '; filename="' . basename($this->_file) . '"';
        }
        $this->_headers['Content-Disposition'] = $cd;
    }
    
    /**
    * Set content type of file for download
    *
    * Returns PEAR_Error if <var>$content_type</var> doesn't seem to be valid.
    * 
    * @throws   PEAR_Error
    * @access   public
    * @return   mixed   true on success or PEAR_Error
    * @param    string  $content_type   content type of file for download
    */
    function setContentType($content_type = 'application/x-octetstream')
    {
        if (!preg_match('/^[a-z]+\w*\/[a-z]+[\w.;= -]*$/', $content_type)) {
            return PEAR::raiseError(
                "Invalid content type '$content_type' supplied.",
                HTTP_DOWNLOAD_E_INVALID_CONTENT_TYPE
            );
        }
        $this->_headers['Content-Type'] = $content_type;
        return true;
    }
    
    /**
    * Send file
    *
    * Returns PEAR_Error if:
    *   o HTTP headers were already sent
    *   o HTTP Range was invalid
    *   o Download was 'not modified since'
    * 
    * @throws   PEAR_Error
    * @access   public
    * @return   mixed   Returns true on success, false if cached or 
    *                   <classname>PEAR_Error</clasname> on failure.
    */
    function send()
    {
        if (headers_sent()) {
            return PEAR::raiseError(
                'Headers already sent.',
                HTTP_DOWNLOAD_E_HEADERS_SENT
            );
        }
        /**
        * Check for partial downloads
        */
        $range = $this->_processRequest();
        if (!$range || PEAR::isError($range)) {
            return $range;
        }
        list($begin, $length) = $range;
        $all = ($begin == 0 && $length == $this->_size);
        /**
        * Check if Content-Disposition header is already set
        */
        if (!isset($this->_headers['Content-Disposition'])) {
            $this->setContentDisposition();
        }
        
        /**
        * HTTP Compression
        */
        if (!$this->_gzip || !@ob_start('ob_gzhandler')) {
            $this->_headers['Content-Length'] = $length;
        }
        
        /**
        * Send HTTP headers
        */
        $this->sendHeaders();

        /**
        * Send requested data (part)
        */
        set_time_limit(0);
        if ($this->_data) {
            echo $all ? $this->_data : substr($this->_data, $begin, $length);
        } else {
            if ($all) {
                if ($this->_handle) {
                    rewind($this->_handle);
                    fpassthru($this->_handle);
                } else {
                    readfile($this->_file);
                }
            } else {
                $rb = 65536;
                if (!$this->_handle) {
                    $this->_handle = fopen($this->_file, 'rb');
                }
                fseek($this->_handle, $begin);
                while(0 < ($length -= $rb)) {
                    echo(fread($this->_handle, $rb));
                }
                echo(fread($this->_handle, $length+$rb));
                fclose($this->_handle);
            }
        }
        return true;
    }
    
    /**
    * Process HTTP request
    * 
    * Check for partial downloads, sane Range headers and HTTP caching.
    *
    * Returns PEAR_Error if:
    *   o download is cached
    *   o HTTP Request was invalid
    * 
    * @access   private
    * @return   mixed   array((int) begin, (int) length) in bytes or PEAR_Error
    */
    function _processRequest()
    {
        $begin      = 0;
        $length     = $this->_size;
        $send_range = false;

        /**
        * Handle Ranges (partial downloads)
        */
        if (isset($_SERVER['HTTP_RANGE'])) {
            $send_range = true;

            // Check for conditional GET
            if (isset($_SERVER['HTTP_IF_UNMODIFIED_SINCE'])) {
                if ($_SERVER['HTTP_IF_UNMODIFIED_SINCE'] != 
                    $this->_last_modified ) 
                {
                    $send_range = false;
                }
            } elseif (isset($_SERVER['HTTP_IF_RANGE'])) {

                // If it doesn't match send the whole thingy
                if ( $_SERVER['HTTP_IF_RANGE'] != $this->_last_modified ) {
                    $send_range = false;
                } 
            }

            /**
            * We don't provide complete http compliance and
            * handle just basic range request (not combined)
            */
            if ($send_range) {
                if (preg_match( '/^bytes=(\d*).*?(\d*)$/i', 
                                $_SERVER['HTTP_RANGE'], 
                                $bytes )) 
                {

                    // First check if there is anything useable in Range header
                    if (    !$bytes[1] && !$bytes[2] && 
                            $bytes[1] !== '0' && $bytes[2] !== '0') {
                        // Range is not valid
                        $this->sendStatusCode(416);
                        return PEAR::raiseError(
                            'HTTP Error: ' . HTTP_HEADER_STATUS_416,
                            HTTP_DOWNLOAD_E_INVALID_REQUEST
                        );
                    }
                    
                    // Calculate the desired Range
                    if (!$bytes[1] && $bytes[1] !== '0') {
                        // OK - Range: bytes=-5
                        $length = $bytes[2];
                        $end    = $this->_size - 1;
                        $begin  = $this->_size - $length;
                    } elseif (!$bytes[2] && $bytes[2] !== '0') {
                        // OK - Range: bytes=5-
                        $begin  = $bytes[1];
                        $end    = $this->_size - 1;
                        $length = $this->_size - $begin;
                    } else {
                        // OK - Range: bytes=5-9
                        $begin  = $bytes[1];
                        $end    = $bytes[2];
                        $length = ($end - $begin) + 1;
                    }

                    // Check if we're out of range
                    if ($end > $this->_size || ($begin + $length) > $this->_size) {
                        $end    = $this->_size - 1;
                        $length = $this->_size - $begin;
                    }
                    
                    // Check if range and file size equal
                    // or second byte offset was less than
                    // the first, so we just ignore the range 
                    // header and send the hole thingy
                    if ($length >= $this->_size || $length <= 0) {
                        $begin      = 0;
                        $end        = $length;
                        $send_range = false;

                    // Check if we're totally out of bounds
                    } elseif ($begin > $this->_size) {
                        // Range is not valid
                        $this->sendStatusCode(416);
                        return PEAR::raiseError(
                            'HTTP Error: ' . HTTP_HEADER_STATUS_416,
                            HTTP_DOWNLOAD_E_INVALID_REQUEST
                        );

                    // Else all's gone fine
                    } else {

                        // Set content range header
                        $this->_headers['Content-Range'] =
                            'bytes: ' . $begin . '-' . $end . '/' . 
                            $this->_size;
                    }

                // If Range header didn't even contain "bytes="
                } else {

                    // Range is not valid
                    $this->sendStatusCode(416);
                    return PEAR::raiseError(
                        'HTTP Error: ' . HTTP_HEADER_STATUS_416,
                        HTTP_DOWNLOAD_E_INVALID_REQUEST
                    );
                }
            }
        }

        /**
        * Don't send data if cached - "HTTP/1.x 304 Not Modified"
        */
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            if ($_SERVER['HTTP_IF_MODIFIED_SINCE'] == $this->_last_modified ) {

                // Not Modified
                $this->sendStatusCode(304);
                $this->sendHeaders(
                    array('Cache-Control', 'Accept-Ranges', 'Connection')
                );
                return false;
            }
        }
        
        /**
        * Send "HTTP/1.x 206 Partial Content" if we send a part
        * Send "HTTP/1.x 200 Ok" if we send the whole thingy
        */
        if ($send_range) {
            $this->sendStatusCode(206);
        } else {
            $this->sendStatusCode(200);
        }
        
        return array($begin, $length);
    }
    
    /**
    * Guess content type of file
    * 
    * This only works if PHP is installed with ext/magic.mime AND php.ini
    * is setup correct! Otherwise it will result in a FATAL ERROR.
    * <b>So be WARNED!</b>
    *
    * Returns PEAR_Error if ext/magic.mime is not installed,
    * or content type couldn't be guessed.
    * 
    * @throws   PEAR_Error
    * @access   public
    * @return   mixed   true on success or PEAR_Error
    */
    function guessContentType()
    {
        if (!function_exists('mime_content_type')) {
            return PEAR::raiseError(
                'This feature requires ext/magic.mime!',
                HTTP_DOWNLOAD_E_NO_EXT_MMAGIC
            );
        }
        if (!is_file(ini_get('mime_magic.magicfile'))) {
            return PEAR::raiseError(
                'mime_magic is loaded but not configured!',
                HTTP_DOWNLOAD_E_NO_EXT_MMAGIC
            );
        }
        return $this->setContentType(mime_content_type($this->_file));
    }
    
    /**
    * Static send
    *
    * @see      HTTP_Download::HTTP_Download()
    * @see      HTTP_Download::send()
    * 
    * @throws   PEAR_Error
    * @static   call this method statically
    * @access   public
    * @return   mixed   true on success or PEAR_Error
    * @param    array   $params     associative array of parameters
    * @param    bool    $guess      whether HTTP_Download::guessContentType()
    *                               should be called
    */
    function staticSend($params, $guess = false)
    {
        $d = &new HTTP_Download();
        $e = $d->setParams($params);
        if (PEAR::isError($e)) {
            return $e;
        }
        if ($guess) {
            $e = $d->guessContentType();
            if (PEAR::isError($e)) {
                return $e;
            }
        }
        return $d->send();
    }
}
?>
