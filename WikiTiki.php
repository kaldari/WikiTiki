<?php
/**
 * WikiTiki MediaWiki Bot Framework
 * https://github.com/kaldari/WikiTiki
 * Version 0.1: 2012-04-04
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * This program is distributed WITHOUT ANY WARRANTY.
 */
 
/**
 * Interface to cURL
 **/
class Http {
	private $curlHandle, $cookieFileId;

	function __construct() {
		if( !function_exists( 'curl_init' ) ) {
			echo( "This bot requires cURL.\n" );
			die();
		}
		$this->cookieFileId = time() . rand( 0, 999 );
		$this->curlHandle = curl_init();
		curl_setopt( $this->curlHandle, CURLOPT_USERAGENT, 'WikiTiki 0.1' );
		curl_setopt( $this->curlHandle, CURLOPT_COOKIEJAR, '/tmp/cookies'.$this->cookieFileId.'.dat' );
		curl_setopt( $this->curlHandle, CURLOPT_COOKIEFILE, '/tmp/cookies'.$this->cookieFileId.'.dat' );
		curl_setopt( $this->curlHandle, CURLOPT_MAXCONNECTS, 10 );
		curl_setopt( $this->curlHandle, CURLOPT_TIMEOUT, 60 );
		curl_setopt( $this->curlHandle, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $this->curlHandle, CURLOPT_CLOSEPOLICY, CURLCLOSEPOLICY_LEAST_RECENTLY_USED );
		curl_setopt( $this->curlHandle, CURLOPT_ENCODING, 'UTF-8' );
		curl_setopt( $this->curlHandle, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $this->curlHandle, CURLOPT_MAXREDIRS, 5 );
		curl_setopt( $this->curlHandle, CURLOPT_HEADER, 0 );
	}

	/**
	 * @param string $url
	 * @return mixed
	 */
	function get( $url ) {
		curl_setopt( $this->curlHandle, CURLOPT_URL, $url );
		curl_setopt( $this->curlHandle, CURLOPT_HTTPGET, 1 );
		curl_setopt( $this->curlHandle, CURLOPT_FOLLOWLOCATION, 1 );
		return curl_exec( $this->curlHandle );
	}

	/**
	 * @param string $url
	 * @param array $postData
	 * @return mixed
	 */
	function post( $url, $postData ) {
		curl_setopt( $this->curlHandle, CURLOPT_URL, $url );
		curl_setopt( $this->curlHandle, CURLOPT_POST, 1 );
		curl_setopt( $this->curlHandle, CURLOPT_FOLLOWLOCATION, 0 );
		curl_setopt( $this->curlHandle, CURLOPT_HTTPHEADER, array( 'Expect:' ) );
		curl_setopt( $this->curlHandle, CURLOPT_POSTFIELDS, $postData );
		return curl_exec( $this->curlHandle );
	}

	function __destruct() {
		curl_close( $this->curlHandle );
		@unlink('/tmp/cookies'.$this->cookieFileId.'.dat');
	}

}

/**
 * Interface to the MediaWiki API
 **/
class WikiTiki {
	private $http, $token;
	// Use English Wikipedia as the default URL
	public $url = 'http://en.wikipedia.org/w/api.php';

	/**
	 * Construct the class instance
	 * @param string $url The URL used to access the API
	 **/
	function __construct( $url = null ) {
		$this->http = new Http;
		if ( $url ) {
			$this->url = $url;
		}
	}

	/**
	 * Send a get query to the API
	 * @param string $query The query string
	 * @return string The result from the API
	 **/
	function get( $query ) {
		$result = $this->http->get( $this->url.$query );
		return unserialize( $result );
	}

	/**
	 * Send a post query to the API
	 * @param string $query The query string
	 * @param array $postData
	 * @return string The result from the API
	 */
	function post( $query, $postData ) {
		$result = $this->http->post( $this->url.$query, $postData );
		return unserialize( $result );
	}

	/**
	 * Log into the wiki via the API
	 * @param string $username The user's username
	 * @param string $password The user's password
	 * @return string|false The result from the API (or false)
	 **/
	function login( $username, $password ) {
		$postData = array( 'lgname' => $username, 'lgpassword' => $password );
		$result = $this->post( '?action=login&format=php', $postData );
		if ( $result['login']['result'] === 'NeedToken' ) {
			// Do it again with the token
			$postData['lgtoken'] = $result['login']['token'];
			$result = $this->post( '?action=login&format=php', $postData );
		}
		if ( isset( $result['login']['result'] ) ) {
			return $result['login']['result'];
		} else {
			return false;
		}
	}

	/**
	 * Get an edit token for the user
	 * @return string|false The token (or false)
	 **/
	function getEditToken() {
		$result = $this->get( '?action=tokens&format=php' );
		if ( isset( $result['tokens']['edittoken'] ) ) {
			return $result['tokens']['edittoken'];
		} else {
			return false;
		}
	}

	/**
	 * Get the contents of a page
	 * @param string $title The title of the wikipedia page to fetch
	 * @return string|false The wikitext for the page (or false)
	 **/
	function getPage( $title ) {
		$params = array(
			'action' => 'query',
			'format' => 'php',
			'prop' => 'revisions',
			'titles' => $title,
			'rvlimit' => 1,
			'rvprop' => 'content'
		);
		$params = http_build_query( $params );
		$result = $this->get('?'.$params );
		foreach ( $result['query']['pages'] as $page ) {
			if ( isset( $page['revisions'][0]['*'] ) ) {
				return $page['revisions'][0]['*'];
			} else {
				return false;
			}
		}
	}

	/**
	 * Get the newest pages from the wiki
	 * @param integer $namespace The namespace to limit the search to
	 * @param integer $limit The maximum number of pages to return
	 * @return array The page titles
	 **/
	function getNewPages( $namespace = 0, $limit = 10 ) {
		$params = array(
			'action' => 'query',
			'list' => 'recentchanges',
			'format' => 'php',
			'rctype' => 'new',
			'rcprop' => 'title',
			'rcnamespace' => $namespace,
			'rclimit' => $limit
		);
		$params = http_build_query( $params );
		$result = $this->get( '?'.$params );
		$pages = $result['query']['recentchanges'];
		$pageTitles = array();
		foreach ( $pages as $page ) {
			$pageTitles[] = $page['title'];
		}
		return $pageTitles;
	}

	/**
	 * Create a new page on the wiki
	 * @param string $title The title of the new page
	 * @param string $text The text of the new page
	 * @param string $summary The edit summary (optional)
	 * @return string|false The result from the API (or false)
	 **/
	function createPage ( $title, $text, $summary = null ) {
		if ( !$this->token ) {
			$this->token = $this->getEditToken();
		}
		if ( !$summary ) {
			$summary = 'Creating new page';
		}
		$params = array(
			'title' => $title,
			'text' => $text,
			'token' => $this->token,
			'summary' => $summary,
			'createonly' => '1'
		);
		$result = $this->post('?action=edit&format=php', $params);
		if ( isset( $result['edit']['result'] ) ) {
			return $result['edit']['result'];
		} else {
			return false;
		}
	}
}
