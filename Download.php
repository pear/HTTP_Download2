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
 * @author      Michael Wallner <mike@php.net>
 * @package     HTTP_Download
 * @category    HTTP
 * @license     PHP License
 */

// {{{ includes
/**
 * Requires PEAR
 */
require_once 'PEAR.php';

/**
 * Requires HTTP_Header
 */
require_once 'HTTP/Header.php';
// }}}

// {{{ constants
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
 * Send as zip archive
 */
define('HTTP_DOWNLOAD_ZIP', 'ZIP');
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
// }}}

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
 * @access   public
 * @version  $Revision$
 */
class HTTP_Download
{
    // {{{ protected member variables
    /**
     * Path to file for download
     *
     * @see     HTTP_Download::setFile()
     * @access  protected
     * @var     string
     */
    var $file = '';
    
    /**
     * Data for download
     *
     * @see     HTTP_Download::setData()
     * @access  protected
     * @var     string
     */
    var $data = null;
    
    /**
     * Resource handle for download
     *
     * @see     HTTP_Download::setResource()
     * @access  protected
     * @var     int
     */
    var $handle = null;
    
    /**
     * Whether to gzip the download
     *
     * @access  protected
     * @var     bool
     */
    var $gzip = false;
    
    /**
     * Whether to allow caching of the download on the clients side
     * 
     * @access  protected
     * @var     bool
     */
    var $cache = true;
    
    /**
     * Size of download
     *
     * @access  protected
     * @var     int
     */
    var $size = 0;
    
    /**
     * Last modified (GMT)
     *
     * @access  protected
     * @var     string
     */
    var $lastModified = '';
    
    /**
     * HTTP headers
     *
     * @access  protected
     * @var     array
     */
    var $headers   = array(
        'Content-Type'  => 'application/x-octetstream',
        'Pragma'        => 'cache',
        'Cache-Control' => 'public, must-revalidate, max-age=0',
        'Accept-Ranges' => 'bytes',
        'Connection'    => 'close',
        'X-Sent-By'     => 'PEAR::HTTP::Download'
    );
 
    /**
     * HTTP_Header
     * 
     * @access  protected
     * @var     object
     */
    var $HTTP = null;
    
    /**
     * ETag
     * 
     * @access  protected
     * @var     string
     */
    var $etag = '';
    
    /**
     * Buffer Size
     * 
     * @access  protected
     * @var     int
     */
    var $bufferSize = 2097152;
    
    /**
     * Throttle Delay
     * 
     * @access  protected
     * @var     int
     */
    var $throttleDelay = 0;
    // }}}
    
    // {{{ constructor
    /**
     * Constructor
     *
     * Set supplied parameters.
     * 
     * @access  public
     * @param   array   $params     associative array of parameters
     * 
     *          <b>one of:</b>
     *                  o 'file'                => path to file for download
     *                  o 'data'                => raw data for download
     *                  o 'resource'            => resource handle for download
     * <br/>
     *          <b>and any of:</b>
     *                  o 'cache'               => whether to allow cs caching
     *                  o 'gzip'                => whether to gzip the download
     *                  o 'lastmodified'        => unix timestamp
     *                  o 'contenttype'         => content type of download
     *                  o 'contentdisposition'  => content disposition
     *                  o 'buffersize'          => amount of bytes to buffer
     *                  o 'throttledelay'       => amount of secs to sleep
     * 
     * <br />
     * 'Content-Disposition' is not HTTP compliant, but most browsers 
     * follow this header, so it was borrowed from MIME standard.
     * 
     * It looks like this: <br />
     * "Content-Disposition: attachment; filename=example.tgz".
     * 
     * @see HTTP_Download::setContentDisposition()
     */
    function HTTP_Download($params = array())
    {
        $this->setParams($params);
        $this->HTTP = &new HTTP_Header;
    }
    // }}}
    
    // {{{ public methods
    /**
     * Set parameters
     * 
     * Set supplied parameters through its accessor methods.
     *
     * @access  public
     * @return  mixed   Returns true on success or PEAR_Error on failure.
     * @param   array   $params     associative array of parameters
     * 
     * @see     HTTP_Download::HTTP_Download()
     */
    function setParams($params)
    {
        foreach((array) $params as $param => $value){
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
     * The Last-Modified header will be set to files filemtime(), actually.
     * Returns PEAR_Error (HTTP_DOWNLOAD_E_INVALID_FILE) if file doesn't exist.
     * Sends HTTP 404 status if $send_404 is set to true.
     * 
     * @access  public
     * @return  mixed   Returns true on success or PEAR_Error on failure.
     * @param   string  $file       path to file for download
     * @param   bool    $send_404   whether to send HTTP/404 if
     *                              the file wasn't found
     */
    function setFile($file, $send_404 = true)
    {
        $file = realpath($file);
        if (!is_file($file)) {
            if ($send_404) {
                $this->HTTP->sendStatusCode(404);
            }
            return PEAR::raiseError(
                "File '$file' not found.",
                HTTP_DOWNLOAD_E_INVALID_FILE
            );
        }
        $this->setLastModified(filemtime($file));
        $this->file = $file;
        $this->size = filesize($file);
        return true;
    }
    
    /**
     * Set data for download
     *
     * Set $data to null if you want to unset this.
     * 
     * @access  public
     * @return  void
     * @param   $data   raw data to send
     */
    function setData($data = null)
    {
        $this->data = $data;
        $this->size = strlen($data);
    }
    
    /**
     * Set resource for download
     *
     * The resource handle supplied will be closed after sending the download.
     * Returns a PEAR_Error (HTTP_DOWNLOAD_E_INVALID_RESOURCE) if $handle 
     * is no valid resource. Set $handle to null if you want to unset this.
     * 
     * @access  public
     * @return  mixed   Returns true on success or PEAR_Error on failure.
     * @param   int     $handle     resource handle
     */
    function setResource($handle = null)
    {
        if (!isset($handle)) {
            $this->handle = null;
            $this->size = 0;
            return true;
        }
        
        if (is_resource($handle)) {
            $this->handle = $handle;
            $filestats    = fstat($handle);
            $this->size   = $filestats['size'];
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
     * Returns a PEAR_Error (HTTP_DOWNLOAD_E_NO_EXT_ZLIB)
     * if ext/zlib is not available/loadable.
     * 
     * @access  public
     * @return  mixed   Returns true on success or PEAR_Error on failure.
     * @param   bool    $gzip   whether to gzip the download
     */
    function setGzip($gzip = false)
    {
        if ($gzip && !PEAR::loadExtension('zlib')){
            return PEAR::raiseError(
                'GZIP compression (ext/zlib) not available.',
                HTTP_DOWNLOAD_E_NO_EXT_ZLIB
            );
        }
        $this->gzip = (bool) $gzip;
        return true;
    }

    /**
     * Whether to allow caching
     * 
     * If set to true (default) we'll send some headers that are commonly
     * used for caching purposes like ETag, Cache-Control and Last-Modified.
     * 
     * If caching is disabled, we'll send the download no matter if it
     * would actually be cached at the client side.
     *
     * @access  public
     * @return  void
     * @param   bool    $cache  whether to allow caching
     */
    function setCache($cache = true)
    {
        $this->cache = (bool) $cache;
    }
    
    /**
     * Set ETag
     * 
     * Sets a user-defined ETag for cache-validation.  The ETag is usually
     * generated by HTTP_Download through its payload information.
     * 
     * @access  public
     * @return  void
     * @param   string  $etag Entity tag used for strong cache validation.
     */
    function setETag($etag = null)
    {
        $this->etag = (string) $etag;
    }
    
    /**
     * Set Size of Buffer
     * 
     * The amount of bytes specified as buffer size is the maximum amount
     * of data read at once from resources or files.  The default size is 2M
     * (2097152 bytes).  Be aware that if you enable gzip compression and
     * you set a very low buffer size that the actual file size may grow
     * due to added gzip headers for each sent chunk of the specified size.
     * 
     * Returns PEAR_Error (HTTP_DOWNLOAD_E_INVALID_PARAM) if $size is not
     * greater than 0 bytes.
     * 
     * @access  public
     * @return  mixed   Returns true on success or PEAR_Error on failure.
     * @param   int     $bytes Amount of bytes to use as buffer.
     */
    function setBufferSize($bytes = 2097152)
    {
        if (0 >= (int) $bytes) {
            return PEAR::raiseError(
                'Buffer size must be greater than 0 bytes ('. $bytes .' given)',
                HTTP_DOWNLOAD_E_INVALID_PARAM);
        }
        $this->bufferSize = (int) $bytes;
        return true;
    }
    
    /**
     * Set Throttle Delay
     * 
     * Set the amount of seconds to sleep after each chunck that has been
     * sent.  One can implement some sort of throttle through adjusting the
     * buffer size and the throttle delay.  With the following settings
     * HTTP_Download will sleep a second after each 25 K of data sent.
     * 
     * <code>
     *  Array(
     *      'throttledelay' => 1,
     *      'buffersize'    => 1024 * 25,
     *  )
     * </code>
     * 
     * Just be aware that if gzipp'ing is enabled, decreasing the chunk size 
     * too much leads to proportionally increased network traffic due to added
     * gzip header and bottom bytes around each chunk.
     * 
     * @access  public
     * @return  void
     * @param   int     $seconds    Amount of seconds to sleep after each 
     *                              chunk that has been sent.
     */
    function setThrottleDelay($seconds = 0)
    {
        $this->throttleDelay = abs((int) $seconds);
    }
    
    /**
     * Set "Last-Modified"
     *
     * This is usually determined by filemtime() in HTTP_Download::setFile()
     * If you set raw data for download with HTTP_Download::setData() and you
     * want do send an appropiate "Last-Modified" header, you should call this
     * method.
     * 
     * @access  public
     * @return  void
     * @param   int     unix timestamp
     */
    function setLastModified($last_modified)
    {
        $this->lastModified             = HTTP::Date((int) $last_modified);
        $this->headers['Last-Modified'] = (int) $last_modified;
    }
    
    /**
     * Set Content-Disposition header
     * 
     * @see HTTP_Download::HTTP_Download
     *
     * @access  public
     * @return  void
     * @param   string  $disposition    whether to send the download
     *                                  inline or as attachment
     * @param   string  $file_name      the filename to display in
     *                                  the browser's download window
     * 
     * <b>Example:</b>
     * <code>
     * $HTTP_Download->setContentDisposition(
     *   HTTP_DOWNLOAD_ATTACHMENT,
     *   'download.tgz'
     * );
     * </code>
     */
    function setContentDisposition( $disposition    = HTTP_DOWNLOAD_ATTACHMENT, 
                                    $file_name      = null)
    {
        $cd = $disposition;
        if (isset($file_name)) {
            $cd .= '; filename="' . $file_name . '"';
        } elseif ($this->file) {
            $cd .= '; filename="' . basename($this->file) . '"';
        }
        $this->headers['Content-Disposition'] = $cd;
    }
    
    /**
     * Set content type of the download
     *
     * Default content type of the download will be 'application/x-octetstream'.
     * Returns PEAR_Error (HTTP_DOWNLOAD_E_INVALID_CONTENT_TYPE) if 
     * $content_type doesn't seem to be valid.
     * 
     * @access  public
     * @return  mixed   Returns true on success or PEAR_Error on failure.
     * @param   string  $content_type   content type of file for download
     */
    function setContentType($content_type = 'application/x-octetstream')
    {
        if (!preg_match('/^[a-z]+\w*\/[a-z]+[\w.;= -]*$/', $content_type)) {
            return PEAR::raiseError(
                "Invalid content type '$content_type' supplied.",
                HTTP_DOWNLOAD_E_INVALID_CONTENT_TYPE
            );
        }
        $this->headers['Content-Type'] = $content_type;
        return true;
    }
    
    /**
     * Guess content type of file
     * 
     * This only works if PHP is installed with ext/magic.mime AND php.ini
     * is setup correct! Otherwise it will result in a FATAL ERROR.
     * <b>So be WARNED!</b>
     *
     * Returns PEAR_Error if:
     *      o ext/magic.mime is not installed, or not properly configured
     *        (HTTP_DOWNLOAD_E_NO_EXT_MMAGIC)
     *      o mime_content_type() couldn't guess content type or returned
     *        a content type considered to be bogus by setContentType()
     *        (HTTP_DONWLOAD_E_INVALID_CONTENT_TYPE)
     * 
     * @access  public
     * @return  mixed   Returns true on success or PEAR_Error on failure.
     */
    function guessContentType()
    {
        if (!function_exists('mime_content_type')) {
            return PEAR::raiseError(
                'This feature requires ext/mime_magic!',
                HTTP_DOWNLOAD_E_NO_EXT_MMAGIC
            );
        }
        if (!is_file(ini_get('mime_magic.magicfile'))) {
            return PEAR::raiseError(
                'ext/mime_magic is loaded but not properly configured!',
                HTTP_DOWNLOAD_E_NO_EXT_MMAGIC
            );
        }
        if (!$content_type = @mime_content_type($this->file)) {
            return PEAR::raiseError(
                'Couldn\'t guess content type with mime_content_type().',
                HTTP_DONWLOAD_E_INVALID_CONTENT_TYPE
            );
        }
        return $this->setContentType($content_type);
    }

    /**
     * Send
     *
     * Returns PEAR_Error if:
     *   o HTTP headers were already sent (HTTP_DOWNLOAD_E_HEADERS_SENT)
     *   o HTTP Range was invalid (HTTP_DOWNLOAD_E_INVALID_REQUEST)
     * 
     * @access  public
     * @return  mixed   Returns true on success or PEAR_Error on failure.
     * @param   bool    $autoSetContentDisposition Whether to set the
     *                  Content-Disposition header if it isn't already.
     */
    function send($autoSetContentDisposition = true)
    {
        if (headers_sent()) {
            return PEAR::raiseError(
                'Headers already sent.',
                HTTP_DOWNLOAD_E_HEADERS_SENT
            );
        }

        set_time_limit(0);
        
        if ($autoSetContentDisposition && 
            !isset($this->headers['Content-Disposition'])) {
            $this->setContentDisposition();
        }
        
        if ($this->cache) {
            $this->headers['ETag'] = $this->generateETag();
            if ($this->isCached()) {
                $this->HTTP->sendStatusCode(304);
                $this->sendHeaders();
                return true;
            }
        } else {
            unset(
                $this->headers['Pragma'],
                $this->headers['Last-Modified'],
                $this->headers['Cache-Control']
            );
        }
        
        if ($this->gzip) {
            @ob_start('ob_gzhandler');
        } else {
            ob_start();
        }
        
        if ($this->isRangeRequest()) {
            $this->HTTP->sendStatusCode(206);
            $chunks = $this->getChunks();
        } else {
            $this->HTTP->sendStatusCode(200);
            $chunks = array(array(0, $this->size));
            if (!$this->gzip) {
                $this->headers['Content-Length'] = $this->size;
            }
        }

        if (PEAR::isError($e = $this->sendChunks($chunks))) {
            ob_end_clean();
            $this->HTTP->sendStatusCode(416);
            return $e;
        }
        
        ob_end_flush();
        return true;
    }    

    /**
     * Static send
     *
     * @see     HTTP_Download::HTTP_Download()
     * @see     HTTP_Download::send()
     * 
     * @static
     * @access  public
     * @return  mixed   Returns true on success or PEAR_Error on failure.
     * @param   array   $params     associative array of parameters
     * @param   bool    $guess      whether HTTP_Download::guessContentType()
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
     * Example:
     * <code>
     *  require_once 'HTTP/Download.php';
     *  HTTP_Download::sendArchive(
     *      'myArchive.tgz',
     *      '/var/ftp/pub/mike',
     *      HTTP_DOWNLOAD_BZ2,
     *      '',
     *      '/var/ftp/pub'
     *  );
     * </code>
     *
     * @see     Archive_Tar::createModify()
     * @static
     * @access  public
     * @return  mixed   Returns true on success or PEAR_Error on failure.
     * @param   string  $name       name the sent archive should have
     * @param   mixed   $files      files/directories
     * @param   string  $type       archive type
     * @param   string  $add_path   path that should be prepended to the files
     * @param   string  $strip_path path that should be stripped from the files
     */
    function sendArchive(   $name, 
                            $files, 
                            $type       = HTTP_DOWNLOAD_TGZ, 
                            $add_path   = '', 
                            $strip_path = '')
    {
        require_once 'System.php';
        
        $tmp = System::mktemp();
        
        switch ($type = strToUpper($type))
        {
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

            case HTTP_DOWNLOAD_ZIP:
                include_once 'Archive/Zip.php';
                $arc = &new Archive_Zip($tmp);
                $content_type = 'x-zip';
            break;
            
            default:
                return PEAR::raiseError(
                    'Archive type not supported: ' . $type,
                    HTTP_DOWNLOAD_E_INVALID_ARCHIVE_TYPE
                );
        }
        
        if ($type == HTTP_DOWNLOAD_ZIP) {
            $options = array(   'add_path' => $add_path, 
                                'remove_path' => $strip_path);
            if (!$arc->create($files, $options)) {
                return PEAR::raiseError('Archive creation failed.');
            }
        } else {
            if (!$e = $arc->createModify($files, $add_path, $strip_path)) {
                return PEAR::raiseError('Archive creation failed.');
            }
            if (PEAR::isError($e)) {
                return $e;
            }
        }
        unset($arc);
        
        $dl = &new HTTP_Download(array('file' => $tmp));
        $dl->setContentType('application/' . $content_type);
        $dl->setContentDisposition(HTTP_DOWNLOAD_ATTACHMENT, $name);
        return $dl->send();
    }
    // }}}
    
    // {{{ protected methods
    /** 
     * Generate ETag
     * 
     * @access  protected
     * @return  string
     */
    function generateETag()
    {
        if (!$this->etag) {
            if ($this->data) {
                $md5 = md5($this->data);
            } elseif (is_resource($this->handle)) {
                $md5 = md5(serialize(fstat($this->handle)));
            } else {
                $md5 = md5_file($this->file);
            }
            $this->etag = '"' . $md5 . '-' . crc32($md5) . '"';
        }
        return $this->etag;
    }
    
    /** 
     * Send multiple chunks
     * 
     * @access  protected
     * @return  mixed   Returns true on success or PEAR_Error on failure.
     * @param   array   $chunks
     */
    function sendChunks($chunks)
    {
        if (count($chunks) == 1) {
            return $this->sendChunk(array_shift($chunks));
        }

        $bound = uniqid('HTTP_DOWNLOAD-', true);
        $cType = $this->headers['Content-Type'];
        $this->headers['Content-Type'] =
            'multipart/byteranges; boundary=' . $bound;
        $this->sendHeaders();
        foreach ($chunks as $chunk){
            if (PEAR::isError($e = $this->sendChunk($chunk, $cType, $bound))) {
                return $e;
            }
        }
        echo "\n--$bound";
        return true;
    }
    
    /**
     * Send chunk of data
     * 
     * @access  protected
     * @return  mixed   Returns true on success or PEAR_Error on failure.
     * @param   array   $chunk  start and end offset of the chunk to send
     * @param   string  $cType  actual content type
     * @param   string  $bound  boundary for multipart/byteranges
     */
    function sendChunk($chunk, $cType = null, $bound = null)
    {
        list($offset, $lastbyte) = $chunk;
        $length = ($lastbyte - $offset) + 1;
        
        if ($length < 1) {
            return PEAR::raiseError(
                "Error processing range request: $offset-$lastbyte/$length",
                HTTP_DOWNLOAD_E_INVALID_REQUEST
            );
        }
        
        $range = $offset . '-' . $lastbyte . '/' . $this->size;
        
        if (isset($cType, $bound)) {
            echo    "\n--$bound\n",
                    "Content-Type: $cType\n",
                    "Content-Range: $range\n\n";
        } else {
            if ($this->isRangeRequest()) {
                $this->headers['Content-Range'] = $range;
            }
            $this->sendHeaders();
        }

        if ($this->data) {
            echo substr($this->data, $offset, $length);
        } else {
            if (!is_resource($this->handle)) {
                $this->handle = fopen($this->file, 'rb');
            }
            fseek($this->handle, $offset);
            while (($length -= $this->bufferSize) > 0) {
                echo fread($this->handle, $this->bufferSize);
                ob_flush();
                if ($this->throttleDelay) {
                    sleep($this->throttleDelay);
                }
            }
            if ($length) {
                echo fread($this->handle, $this->bufferSize + $length);
                ob_flush();
            }
        }
        return true;
    }
    
    /** 
     * Get chunks to send
     * 
     * @access  protected
     * @return  array
     */
    function getChunks()
    {
        $parts = array();
        foreach (explode(',', $this->getRanges()) as $chunk){
            list($o, $e) = explode('-', $chunk);
            if ($e >= $this->size || (empty($e) && $e !== 0 && $e !== '0')) {
                $e = $this->size - 1;
            }
            if (empty($o) && $o !== 0 && $o !== '0') {
                $o = $this->size - $e;
                $e = $this->size - 1;
            }
            $parts[] = array($o, $e);
        }
        return $parts;
    }
    
    /** 
     * Check if range is requested
     * 
     * @access  protected
     * @return  bool
     */
    function isRangeRequest()
    {
        if (!isset($_SERVER['HTTP_RANGE'])) {
            return false;
        }
        return $this->isValidRange();
    }
    
    /** 
     * Get range request
     * 
     * @access  protected
     * @return  array
     */
    function getRanges()
    {
        return preg_match('/^bytes=((\d*-\d*,?)+)$/', 
            @$_SERVER['HTTP_RANGE'], $matches) ? $matches[1] : array();
    }
    
    /** 
     * Check if entity is cached
     * 
     * @access  protected
     * @return  bool
     */
    function isCached()
    {
        return (
            (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) &&
            $this->lastModified === array_shift(explode(';', 
                $_SERVER['HTTP_IF_MODIFIED_SINCE']))) ||
            (isset($_SERVER['HTTP_IF_NONE_MATCH']) &&
            $this->compareAsterisk('HTTP_IF_NONE_MATCH', $this->etag))
        );
    }
    
    /** 
     * Check if entity hasn't changed
     * 
     * @access  protected
     * @return  bool
     */
    function isValidRange()
    {
        if (isset($_SERVER['HTTP_IF_MATCH']) &&
            !$this->compareAsterisk('HTTP_IF_MATCH', $this->etag)) {
            return false;
        }
        if (isset($_SERVER['HTTP_IF_UNMODIFIED_SINCE']) && 
            array_shift(explode(';', $_SERVER['HTTP_IF_UNMODIFIED_SINCE'])) !== 
                $this->lastModified) {
            return false;
        }
        return true;
    }
    
    /** 
     * Compare against an asterisk or check for equality
     * 
     * @access  protected
     * @return  bool
     * @param   string  key for the $_SERVER array
     * @param   string  string to compare
     */
    function compareAsterisk($svar, $compare)
    {
        foreach (array_map('trim', explode(',', $_SERVER[$svar])) as $request) {
            if ($request === '*' || $request === $compare) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Send HTTP headers
     *
     * @access  protected
     * @return  void
     */
    function sendHeaders()
    {
        foreach ($this->headers as $header => $value) {
            $this->HTTP->setHeader($header, $value);
        }
        $this->HTTP->sendHeaders();
        ob_flush();
    }
    // }}}
}
?>
