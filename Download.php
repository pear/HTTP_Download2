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
// | Copyright (c) 2003-2004 Michael Wallner <mike@iworks.at>             |
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
require_once 'HTTP/Header.php';

/**#@+ Use with HTTP_Download::setContentDisposition() **/
/**
* Send data as attachment
*/
define('HTTP_DOWNLOAD_ATTACHMENT', 'attachment');
/**
* Send data inline
*/
define('HTTP_DOWNLOAD_INLINE', 'inline');
/**#@-**/

/**#@+ Use with HTTP_Download::sendArchive() **/
/**
* Send as uncompressed tar archive
*/
define('HTTP_DOWNLOAD_TAR', 'TAR');
/**
* Send as gzipped tar archive
*/
define('HTTP_DOWNLOAD_TGZ', 'TGZ');
/**
* Send as bzip2 compressed tar archive
*/
define('HTTP_DOWNLOAD_BZ2', 'BZ2');
/**
* Send as zip archive (not available yet)
*/
#define('HTTP_DOWNLOAD_ZIP', 'ZIP');
/**#@-**/

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
define('HTTP_DOWNLOAD_E_INVALID_ARCHIVE_TYPE',  -9);
/**#@-**/

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
* Usage Example 1:
* <code>
* $params = array(
*   'file'                  => '../hidden/download.tgz',
*   'contenttype'           => 'application/x-gzip',
*   'contentdisposition'    => array(HTTP_DOWNLOAD_ATTACHMENT, 'latest.tgz'),
* );
* 
* $error = HTTP_Download::staticSend($params, false);
* </code>
* 
* 
* Usage Example 2:
* <code>
* $dl = &new HTTP_Download();
* $dl->setFile('../hidden/download.tgz');
* $dl->setContentDisposition(HTTP_DOWNLOAD_ATTACHMENT, 'latest.tgz');
* // with ext/magic.mime
* // $dl->guessContentType();
* // else:
* $dl->setContentType('application/x-gzip');
* $dl->send();
* </code>
* 
* 
* Usage Example 3:
* <code>
* $dl = &new HTTP_Download();
* $dl->setData($blob_from_db);
* $dl->setLastModified($unix_timestamp);
* $dl->setContentType('application/x-gzip');
* $dl->setContentDisposition(HTTP_DOWNLOAD_ATTACHMENT, 'latest.tgz');
* $dl->send();
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
    * ETag
    * 
    * @access   private
    * @var      string
    */
    var $_etag = null;
       
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
        if (!isset($handle)) {
            $this->_handle = null;
            $this->_size = 0;
            return true;
        }
        
        if (is_resource($handle)) {
            $this->_handle  = $handle;
            $filestats      = fstat($handle);
            $this->_size    = $filestats['size'];
            return true;
        }

        return PEAR::raiseError(
            "Handle '$handle' is no valid resource.",
            HTTP_DOWNLOAD_E_INVALID_RESOURCE
        );
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
        if ($gzip && !PEAR::loadExtension('zlib')){
            return PEAR::raiseError(
                'GZIP Compression (ext/zlib) not available.',
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
        if (isset($file_name)) {
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
    * Send
    *
    * Returns PEAR_Error if:
    *   o HTTP headers were already sent
    *   o HTTP Range was invalid
    * 
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

        $this->_headers['ETag'] = $this->_generateETag();
        
        if (!isset($this->_headers['Content-Disposition'])) {
            $this->setContentDisposition();
        }
        
        if ($this->_isCached()) {
            $this->sendStatusCode(304);
            return true;
        }

        if ($this->_gzip) {
            @ob_start('ob_gzhandler');
        } else {
            ob_start();
        }
        
        if ($this->_isRangeRequest()) {
            $this->sendStatusCode(206);
            $chunks = $this->_getChunks();
        } else {
            $this->sendStatusCode(200);
            $chunks = array(array(0, $this->_size));
        }

        if (true !== $e = $this->_sendChunks($chunks)) {
            ob_end_clean();
            $this->sendStatusCode(416);
            return $e;
        }
        
        $this->sendHeaders();
        
        return true;
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
    
    /**
    * Send a bunch of files or directories as an archive
    *
    * @static
    * @access   public
    * @return   mixed   Returns true on success or PEAR_Error on failure
    * @param    string  $name       name the sent archive should have
    * @param    mixed   $files      files/directories
    * @param    string  $type       archive type
    * @param    string  $add_path
    * @param    string  $strip_path
    */
    function sendArchive($name, $files, $type = HTTP_DOWNLOAD_TGZ, $add_path = '', $strip_path = '')
    {
        require_once 'System.php';
        
        $tmp = System::mktemp();
        
        switch (strToUpper($type)) {
            case HTTP_DOWNLOAD_TAR:
                include_once 'Archive/Tar.php';
                $arc = &new Archive_Tar($tmp);
                $content_type = 'x-tar';
                break;

            case HTTP_DOWNLOAD_TGZ:
                include_once 'Archive/Tar.php';
                $arc = &new Archive_Tar($tmp, 'gz');
                $content_type = 'x-gzip';
                break;

            case HTTP_DOWNLOAD_BZ2:
                include_once 'Archive/Tar.php';
                $arc = &new Archive_Tar($tmp, 'bz2');
                $content_type = 'x-bzip2';
                break;

            default:
                return PEAR::raiseError(
                    'Archive type not supported: ' . $type,
                    HTTP_DOWNLOAD_E_INVALID_ARCHIVE_TYPE
                );
        }
        
        if (!$e = $arc->createModify($files, $add_path, $strip_path)) {
            return PEAR::raiseError('Archive creation failed.');
        }
        if (PEAR::isError($e)) {
            return $e;
        }
        unset($arc);
        
        $dl = &new HTTP_Download(array('file' => $tmp));
        $dl->setContentType('application/' . $content_type);
        $dl->setContentDisposition(HTTP_DOWNLOAD_ATTACHMENT, $name);
        return $dl->send();
    }

    /** 
    * Generate ETag
    * 
    * @access   private
    * @return   string
    * @param    bool
    */
    function _generateETag($weak = false)
    {
        if ($this->_data) {
            $md5 = md5($this->_data);
        } elseif (is_resource($this->_handle)) {
            $md5 = md5(serialize(fstat($this->_handle)));
        } else {
            $md5 = md5_file($this->_file);
        }
        return $this->_etag = '"' . $md5 . '-' . crc32($md5) . '"';
    }
    
    /** 
    * Send multiple chunks
    * 
    * @access   public
    * @return   mixed
    */
    function _sendChunks($chunks)
    {
        if (count($chunks) == 1) {
            return $this->_sendChunk(array_shift($chunks));
        } else {

            $bound = uniqid('HTTP_DOWNLOAD-', true);
            $cType = $this->_headers['Content-Type'];
            $this->_headers['Content-Type'] =  'multipart/byteranges; ';
            $this->_headers['Content-Type'] .= 'boundary=' . $bound;

            foreach ($chunks as $chunk){
                if (true !== $e = $this->_sendChunk($chunk, $cType, $bound)) {
                    return $e;
                }
            }
            echo "\n--$bound";
        }
        return true;
    }
    
    /**
    * Send chunk of data
    * 
    * @access   private
    * @return   void
    * @param    array   $chunk
    */
    function _sendChunk($chunk, $cType = null, $bound = null)
    {
        list($offset, $lastbyte) = $chunk;
        $length = ($lastbyte - $offset) + 1;
        
        if ($length < 0) {
            return PEAR::raiseError(
                "Error processing range request: $offset-$lastbyte/$length",
                HTTP_DOWNLOAD_E_INVALID_REQUEST
            );
        }
        
        $range  = $offset . '-' . $lastbyte . '/' . $this->_size;
        
        if (isset($cType, $bound)) {
            echo    "\n--$bound\n",
                    "Content-Type: $cType\n",
                    "Content-Range: $range\n\n";
        } elseif ($this->_isRangeRequest()) {
            $this->_headers['Content-Range'] = $range;
        }

        if ($this->_data) {
            echo substr($this->data, $offset, $length);
        } else {
            $actual = 0;
            if (!$this->_handle) {
                $this->_handle = fopen($this->_file, 'rb');
            }
            fseek($this->_handle, $offset);
            echo fread($this->_handle, $length);
        }
        return true;
    }
    
    /** 
    * Get chunks to send
    * 
    * @access   public
    * @return   mixed
    */
    function _getChunks()
    {
        foreach (explode(',', $this->_getRangeRequest()) as $chunk){
            list($o, $e) = explode('-', $chunk);
            $e = (!$e || $e >= $this->_size ? $this->_size - 1 : $e);
            if (empty($o) && $o !== 0 && $o !== '0') {
                $o = $this->_size - $e;
                $e = $this->_size - 1;
            }
            $parts[] = array($o, $e);
        }
        return $parts;
    }
    
    /** 
    * Check if range is requested
    * 
    * @access   private
    * @return   mixed
    * @param    bool
    */
    function _isRangeRequest()
    {
        if (!isset($_SERVER['HTTP_RANGE'])) {
            return false;
        }
        if (isset($_SERVER['HTTP_IF_UNMODIFIED_SINCE']) && 
            $_SERVER['HTTP_IF_UNMODIFIED_SINCE'] !== $this->_last_modified) {
            return false;
        }
        if (isset($_SERVER['HTTP_IF_RANGE']) &&
            $_SERVER['HTTP_IF_RANGE'] !== $this->_last_modified) {
            return false;
        }
        return true;
    }
    
    /** 
    * Get range request
    * 
    * @access   public
    * @return   array
    */
    function _getRangeRequest()
    {
        $matched = preg_match('/^bytes=((\d*-\d*,?)+)$/', 
            $_SERVER['HTTP_RANGE'], $matches);
        if ($matched) {
            return $matches[1];
        }
        return false;
    }
    
    /** 
    * Check if download should be cached
    * 
    * @access   private
    * @return   bool
    */
    function _isCached()
    {
        if (isset($_SERVER['HTTP_ETAG']) && $_SERVER['HTTP_ETAG'] = $this->_etag) {
            return true;
        }
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) &&
            $_SERVER['HTTP_IF_MODIFIED_SINCE'] == $this->_last_modified) {
            return true;
        }
        return false;
    }
}
?>