<?php
/*
Plugin Name: Scriblio MARC File Connector
Plugin URI: http://about.scriblio.net/
Description: Imports MARC records into Scriblio, provides functions used by other importers.
Version: .02
Author: Casey Bisson
Author URI: http://maisonbisson.com/blog/
*/
/* Copyright 2007-2009 Casey Bisson & Plymouth State University

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
*/

// The importer
class Scrib_marc {
	var $importer_code = 'scribmarc';
	var $importer_name = 'Scriblio MARC File Connector';
	var $importer_desc = 'Imports MARC records into Scriblio, provides functions used by other importers. <a href="http://about.scriblio.net/wiki">Documentation here</a>.';
	
	// Function that will handle the wizard-like behaviour
	function dispatch() {
		if( empty ($_GET['step']))
			$step = 0;
		else
			$step = (int) $_GET['step'];

		// load the header
		$this->header();

		switch ($step) {
			case 0 :
				$this->greet();
				break;
			case 1 :
				check_admin_referer('import-upload');
				$this->accept_file();
				break;
			case 2:
				$this->parse_file();
				break;
			case 3:
				$this->ktnxbye();
				break;
		}

		// load the footer
		$this->footer();
	}

	function header() {
		echo '<div class="wrap">';
		echo '<h2>'.__('Scriblio Catalog Importer').'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function greet() {
		echo '<div class="narrow">';
		echo '<p>'.__('Howdy! Start here to import MARC records into Scriblio.').'</p>';
		echo '<p>'.__('This has not been tested much. Mileage may vary.').'</p>';

		echo '<br /><br />';	
		wp_import_upload_form("admin.php?import=$this->importer_code&amp;step=1");
		echo '</div>';
	}

	function ktnxbye() {
		echo '<div class="narrow">';
		echo '<p>'.__('All done.').'</p>';
		echo '</div>';
	}

	function accept_file(){
		$prefs = get_option('scrib_marcimporter');
		$prefs['scrib_marc-warnings'] = array();
		$prefs['scrib_marc-errors'] = array();
		$prefs['scrib_marc-record_start'] = 0;
		$prefs['scrib_marc-records_harvested'] = 0;
		update_option('scrib_marcimporter', $prefs);

		$this->options();
	}

	function options(){
		global $file;
	
		if(empty($this->id)){
			$file = wp_import_handle_upload();
			if(  isset($file['error']) ) {
				echo '<p>'.__('Sorry, there has been an error.').'</p>';
				echo '<p><strong>' . $file['error'] . '</strong></p>';
				return;
			}
			$this->file = $file['file'];
			$this->id = (int) $file['id'];
		}
	
		$prefs = get_option('scrib_marcimporter');

		echo '<div class="narrow">';
		echo '<p>'.__('MARC file options.').'</p>';

		echo '<p>'.__('All Scriblio records have a &#039;sourceid,&#039; a unique alphanumeric string that&#039;s used to avoid creating duplicate records and, in some installations, link back to the source system for current availability information.').'</p>';
		echo '<p>'.__('The sourceid is made up of two parts: the prefix that you assign, and a ID info from the source record. Many systems assign unique numbers to each record, the challenge is figuring out which field to use.').'</p>';

		echo '<form name="myform" id="myform" action="admin.php?import='. $this->importer_code .'&amp;id='. $this->id .'&amp;step=2" method="post">';
?>
<p><label for="scrib_marc-sourceprefix">The source prefix:<br /><input type="text" name="scrib_marc-sourceprefix" id="scrib_marc-sourceprefix" value="<?php echo attribute_escape( $prefs['scrib_marc-sourceprefix'] ); ?>" /><br />example: bb (must be two characters, a-z and 0-9 accepted)</label></p>
<p><label for="scrib_marc-sourcefield">The source ID field:<br /><input type="text" name="scrib_marc-sourcefield" id="scrib_marc-sourcefield" value="<?php echo attribute_escape( $prefs['scrib_marc-sourcefield'] ); ?>" /><br />example: to use barcodes from Infocenter: [852][0]->subfields['p']<br /> or to use the the Project Gutenburg ID: ['000'][0]->data<br /> or to use the LCCN: ['001'][0]->data</label></p>
<p><br /><br /><label for="scrib_marc-record_start">Start with record number:<br /><input type="text" name="scrib_marc-record_start" id="scrib_marc-record_start" value="<?php echo attribute_escape( $prefs['scrib_marc-record_start'] ); ?>" /></label></p>
<p><label for="scrib_marc-debug"><input type="checkbox" name="scrib_marc-debug" id="scrib_marc-debug" value="1" /> Turn on debug mode.</label></p>
<?php
		echo '<p class="submit"><input type="submit" name="next" value="'.__('Next &raquo;').'" /></p>';
		echo '</form>';
		echo '</div>';
	}

	function parse_file(){
		$interval = 2500;
		if( empty( $_REQUEST[ 'scrib_marc-record_start' ] ))
			$n = 0;
		else
			$n = (int) $_REQUEST[ 'scrib_marc-record_start' ];

		ini_set('memory_limit', '1024M');
		set_time_limit(0);
		ignore_user_abort(TRUE);

		$this->id = (int) $_GET['id'];
		$this->file = get_attached_file($this->id);

		if(empty($_POST['scrib_marc-sourceprefix']) || empty($_POST['scrib_marc-sourcefield']) || empty($this->file)){
			echo '<p>'.__('Sorry, there has been an error.').'</p>';
			echo '<p><strong>Please complete all fields</strong></p>';
			return;
		}

		// save these settings so we can try them again later
		$prefs = get_option('scrib_marcimporter');
		$prefs['scrib_marc-sourceprefix'] = stripslashes($_POST['scrib_marc-sourceprefix']);
		$prefs['scrib_marc-sourcefield'] = stripslashes($_POST['scrib_marc-sourcefield']);
		update_option('scrib_marcimporter', $prefs);

		error_reporting(E_ERROR);

		// initialize the marc library
		require_once(ABSPATH . PLUGINDIR .'/'. plugin_basename(dirname(__FILE__)) .'/php-marc.php');
		$file = new File($this->file);

		$prefs['scrib_marc-records_count'] = count($file->raw);
		update_option('scrib_marcimporter', $prefs);

		if($n > 0 || count($file->raw) > $interval)
			$file->raw = array_slice($file->raw, $n, $interval);

		if(!empty($_POST['scrib_marc-debug'])){

			$record = $file->next();

			echo '<h3>The MARC Record:</h3><pre>';			
			print_r($record->fields());

			echo '</pre><h3>The Tags and Display Record:</h3><pre>';
			print_r( $this->parse_record( $record->fields() ));
			echo '</pre>';

			echo '<h3>The SourceID: '. $test_pancake['the_sourceid'] .'</h3>';
			
			// bring back that form
			echo '<h2>'.__('File Options').'</h2>';
			echo '<p>File has '. $prefs['scrib_marc-records_count'] .' records.</p>';
			$this->options();
		
		}else{
			// import with status
			$count = 0;
			echo "<p>Reading the file and parsing ". $file->num_records() ." records. Please be patient.<br /><br /></p>";
			echo '<ol>';
			while($file->pointer < count($file->raw)){
				if($record = $file->next()){
					$bibr = &$this->parse_record($record->fields());
					echo "<li>{$bibr['the_title']} {$bibr['the_sourceid']}</li>";
					$count++;
				}
			}
			echo '</ol>';
			
			$prefs['scrib_marc-warnings'] = array_merge($prefs['scrib_marc-warnings'], $file->warn);
			$prefs['scrib_marc-errors'] = array_merge($prefs['scrib_marc-errors'], $file->error);
			$prefs['scrib_marc-records_harvested'] = $prefs['scrib_marc-records_harvested'] + $count;
			update_option('scrib_marcimporter', $prefs);

			if(count($file->raw) >= $interval){
				$prefs['scrib_marc-record_start'] = $n + $interval;
				update_option('scrib_marcimporter', $prefs);

				$this->options();
				?>
				<div class="narrow"><p><?php _e("If your browser doesn't start loading the next page automatically click this link:"); ?> <a href="javascript:nextpage()"><?php _e("Next Records"); ?></a> </p>
				<script language='javascript'>
				<!--
	
				function nextpage() {
					document.getElementById('myform').submit();
				}
				setTimeout( "nextpage()", 1250 );
	
				//-->
				</script>
				</div>
				<?php
			}else{
				$this->done();
				?>
				<script language='javascript'>
				<!--
					window.location='#complete';
				//-->
				</script>
				</div>
				<?php
			}
		}
	}
	
	function parse_record($marcrecord){
		global $scrib;

		$spare_keys = array( 'a', 'b', 'c', 'd', 'e', 'f', 'g' );
		$atomic = $subjtemp = array();

		foreach($marcrecord as $fields){
			foreach($fields as $field){
				// languages
				if( $field->tagno == '008' ){
					$atomic['published'][0]['lang'][] = $scrib->meditor_sanitize_punctuation( substr( $field->data, 35,3 ));

				}else if( $field->tagno == '041' ){
					foreach( $field->subfields as $key => $val ){
						switch( $key[0] ){
							case 'a':
							case 'b':
							case 'c':
							case 'd':
							case 'e':
							case 'f':
							case 'g':
							case 'h':
								$atomic['published'][0]['lang'][] = $scrib->meditor_sanitize_punctuation( $val );
						}
					}

	
				//Standard Numbers
				}else if($field->tagno == 10){
					$atomic['idnumbers'][] = array( 'type' => 'lccn', 'id' => $field->subfields['a'] );
	
				}else if($field->tagno == 20){
					$temp = trim($field->subfields['a']) . ' ';
					$temp = preg_replace('/[^0-9x]/i', '', strtolower(substr($temp, 0, strpos($temp, ' '))));
					if( strlen( $temp ))
						$atomic['idnumbers'][] = array( 'type' => 'isbn', 'id' => $temp );
	
				}else if($field->tagno == 22){
					$temp = trim($field->subfields['a']) . ' ';
					$temp = preg_replace('/[^0-9x\-]/i', '', strtolower(substr($temp, 0, strpos($temp, ' '))));
					if( strlen( $temp ))
						$atomic['idnumbers'][] = array( 'type' => 'issn', 'id' => $temp );
	
					$temp = trim($field->subfields['y']) . ' ';
					$temp = preg_replace('/[^0-9x\-]/i', '', strtolower(substr($temp, 0, strpos($temp, ' '))));
					if( strlen( $temp ))
						$atomic['idnumbers'][] = array( 'type' => 'issn', 'id' => $temp );
	
					$temp = trim($field->subfields['z']) . ' ';
					$temp = preg_replace('/[^0-9x\-]/i', '', strtolower(substr($temp, 0, strpos($temp, ' '))));
					if( strlen( $temp ))
						$atomic['idnumbers'][] = array( 'type' => 'issn', 'id' => $temp );

				// authors
				}else if(($field->tagno == 100) || ($field->tagno == 700) || ($field->tagno == 110) || ($field->tagno == 710) || ($field->tagno == 111) || ($field->tagno == 711)){
					$temp = $field->subfields['a'];
					unset( $temp_role );
					if(($field->tagno == 100) || ($field->tagno == 700)){
						if( $field->subfields['d'] )
							$temp .= ' ' . $field->subfields['d'];
						if( $field->subfields['e'] )
							$temp_role = ' ' . $field->subfields['e'];
					}else if(($field->tagno == 110) || ($field->tagno == 710)){
						if( $field->subfields['b'] ) {
							$temp .= ' ' . $field->subfields['b'];
						}
					}else if(($field->tagno == 111) || ($field->tagno == 711)){
						if( $field->subfields['n'] ) {
							$temp .= ' ' . $field->subfields['n'];
						}
						if( $field->subfields['d'] ) {
							$temp .= ' ' . $field->subfields['d'];
						}
						if( $field->subfields['c'] ) {
							$temp .= ' ' . $field->subfields['c'];
						}
					}
					$temp = ereg_replace('[,|\.]$', '', $temp);
					$atomic['creator'][] = array( 'name' => $scrib->meditor_sanitize_punctuation( $temp ), 'role' => $temp_role ? $temp_role : 'Author' );
	
					//handle title in name
					$temp = '';
					if( $field->subfields['t'] ) {
						$temp .= ' ' . $field->subfields['t'];
					}
					if( $field->subfields['n']) {
						$temp .= ' ' . $field->subfields['n'];
					}
					if( $field->subfields['p'] ) {
						$temp .= ' ' . $field->subfields['p'];
					}
					if( $field->subfields['l'] ) {
						$temp .= ' ' . $field->subfields['l'];
					}
					if( $field->subfields['k'] ) {
						$temp .= ' ' . $field->subfields['k'];
					}
					if( $field->subfields['f'] ) {
						$temp .= ' ' . $field->subfields['f'];
					}
					$temp = ereg_replace('[,|\.]$', '', $temp);
					if( strlen($temp) >0) {
						$atomic['alttitle'][] = array( 'a' => $scrib->meditor_sanitize_punctuation( $temp ));
					}
	

/*
				
				//Call Numbers
				}else if($field->tagno == 852){ // callnums from InfoCenter
					$temp = trim($field->subfields['h']);
					$atomic['callnumber'][] = $temp;

					$temp = trim($field->subfields['b']);
					$atomic['location'][] = $temp;

					$_acqdate[] = $field->subfields[x]{14}.$field->subfields[x]{15}.$field->subfields[x]{16}.$field->subfields[x]{17} .'-'. $field->subfields[x]{18}.$field->subfields[x]{19} .'-'. $field->subfields[x]{20}.$field->subfields[x]{21};

				//Dates
				}else if($field->tagno == 260){
					$temp = str_pad(substr(ereg_replace('[^0-9]', '', $field->subfields['c']), 0, 4), 4 , '5');
					$atomic['pubyear'][] = $temp;
				}else if($field->tagno == 005){
					$atomic['catdate'][] = $field->data{0}.$field->data{1}.$field->data{2}.$field->data{3} .'-'. $field->data{4}.$field->data{5} .'-'. $field->data{6}.$field->data{7};
				}else if($field->tagno == 008){
					$atomic['pubyear'][] = substr($field->data, 14, 4);
				
				//Notes
				}else if(($field->tagno > 299) && ($field->tagno < 400)){
					$atomic['physdesc'][] = implode(' ', array_values($field->subfields));
				}else if(($field->tagno > 399) && ($field->tagno < 500)){
					$atomic['title'][] = implode("\n", array_values($field->subfields));
				}else if(($field->tagno > 799) && ($field->tagno < 841)){
					$atomic['series'][] = implode("\n", array_values($field->subfields));
				}else if(($field->tagno > 499) && ($field->tagno < 600)){
					$line = implode("\n", array_values($field->subfields));
					if($field->tagno == 504)
						continue;
					if($field->tagno == 505){
						$atomic['contents'][] = str_replace(array('> ','> ','> '), '>', '<li>'. str_replace('--', "</li>\n<li>", trim(str_replace(array(' ', ' ', ' '), ' ', $line))) .'</li>');
						continue;
					}
					$atomic['notes'][] = str_replace(' ', ' ', $line);
				}
				
				//Format
				if((!$atomic['format']) && ($field->tagno > 239) && ($field->tagno < 246)){
					$temp = ucwords(strtolower(str_replace('[', '', str_replace(']', '', $field->subfields['h']))));
					
					if(eregi('^book', $temp)){
						$format = 'Book';
						$formats = 'Books';
	
					}else if(eregi('^micr', $temp)){
						$format = 'Microform';
	
					}else if(eregi('^electr', $temp)){
						$format = 'Website';
						$formats = 'Websites';
	
					}else if(eregi('^vid', $temp)){
						$format = 'Video';
					}else if(eregi('^motion', $temp)){
						$format = 'Video';
	
					}else if(eregi('^audi', $temp)){
						$format = 'Audio';
					}else if(eregi('^cass', $temp)){
						$format = 'Audio';
					}else if(eregi('^phono', $temp)){
						$format = 'Audio';
					}else if(eregi('^record', $temp)){
						$format = 'Audio';
					}else if(eregi('^sound', $temp)){
						$format = 'Audio';
	
					}else if(eregi('^carto', $temp)){
						$format = 'Map';
						$formats = 'Maps';
					}else if(eregi('^map', $temp)){
						$format = 'Map';
						$formats = 'Maps';
					}else if(eregi('^globe', $temp)){
						$format = 'Map';
						$formats = 'Maps';
	
					}else if($temp){
						$format = 'Classroom Material';
						//$format = $temp;
					}
	
					if(!$formats)
						$formats = $format;
					
					if($format){
						$atomic['format'][] = $format;
						$atomic['formats'][] = $formats;
					}
				}
			}
		}

		if(!$atomic['format'][0]){
			$atomic['format'][0] = 'Book';
		}

		if(!$atomic['catdate'][0])
			$atomic['catdate'][0] = '1984-01-01';

		if($atomic['pubyear'][0] > (date(Y) + 5))
			$atomic['pubyear'][0] = substr($atomic['catdate'][0],0,4);

		if($atomic['pubyear'][0])
			$atomic['pubdate'][] = $atomic['pubyear'][0].substr($atomic['catdate'][0],4);
		
		if($atomic['alttitle'])
			$atomic['title'] = array_unique(array_merge($atomic['title'], $atomic['alttitle']));


		// sanity check the _acqdate
		$_acqdate = array_unique($_acqdate);
		foreach( $_acqdate as $key => $temp )
			if( strtotime( $temp ) > strtotime( date('Y') + 2 ))
				unset( $_acqdate[$key] );
		$_acqdate = array_values( $_acqdate );
		if( !isset( $_acqdate[0] ))
			if( isset( $atomic['pubyear'][0] ))
				$_acqdate[0] = $atomic['pubyear'][0] .'-01-01';
			else
				$_acqdate[0] = ( date('Y') - 1 ) .'-01-01';
		$_acqdate = $_acqdate[0];

		$_sourceid = substr(ereg_replace('[^a-z|0-9]', '', strtolower($_POST['scrib_marc-sourceprefix'])), 0, 2) . trim(eval('return($marcrecord'. str_replace(array('(',')','$'), '', stripslashes($_POST['scrib_marc-sourcefield'])) .');'));


*/
























	
	
				
				//Call Numbers
				}else if($field->tagno == 50){
					$atomic['callnumbers'][] = array( 'type' => 'lc', 'number' => implode( ' ', $marc ));
				}else if($field->tagno == 82){
					$atomic['callnumbers'][] = array( 'type' => 'dewey', 'number' => str_replace( '/', '', $field->subfields['a'] ));
	
				//Titles
				}else if($field->tagno == 130){
					$atomic['alttitle'][] = array( 'a' => $scrib->meditor_sanitize_punctuation( $field->subfields['a'] ));
				}else if($field->tagno == 245){
					$temp = trim(ereg_replace('/$', '', $field->subfields['a']) .' '. trim(ereg_replace('/$', '', $field->subfields['b']) .' '. trim(ereg_replace('/$', '', $field->subfields['n']) .' '. trim(ereg_replace('/$', '', $field->subfields['p'])))));
					$atomic['title'][] = array( 'a' => $scrib->meditor_sanitize_punctuation( $temp ));
					$atomic['attribution'][] = array( 'a' => $scrib->meditor_sanitize_punctuation( $field->subfields['c'] ));
				}else if($field->tagno == 240){
					$atomic['alttitle'][] = array( 'a' => $scrib->meditor_sanitize_punctuation( implode(' ', array_values( $marc ))));
				}else if($field->tagno == 246){
					$temp = trim(ereg_replace('/$', '', $field->subfields['a']) .' '. trim(ereg_replace('/$', '', $field->subfields['b']) .' '. trim(ereg_replace('/$', '', $field->subfields['n']) .' '. trim(ereg_replace('/$', '', $field->subfields['p'])))));
					$atomic['alttitle'][] = array( 'a' => $scrib->meditor_sanitize_punctuation( $temp ));
				}else if(($field->tagno > 719) && ($field->tagno < 741)){
					$temp = $field->subfields['a'];
					if ($field->subfields['n']) {
						$temp .= ' ' .$field->subfields['n'];
					}
					if ($field->subfields['p']) {
						$temp .= ' ' . $field->subfields['p'];
					}
					$temp = ereg_replace('[,|\.|;]$', '', $temp);
					if (strlen($temp) >0) {
						$atomic['alttitle'][] = array( 'a' => $scrib->meditor_sanitize_punctuation( $temp ));
					}
	
				//Edition
				}else if($field->tagno == 250){
					$atomic['published'][0]['edition'] = $scrib->meditor_sanitize_punctuation( implode(' ', $marc));
	
				//Dates and Publisher
				}else if($field->tagno == 260){
					if($field->subfields['b']){
						$atomic['published'][0]['publisher'][] = $scrib->meditor_sanitize_punctuation($field->subfields['b']);
					}
	
					if($field->subfields['c']){
						$temp ="";
						//match for year pattern, such as "1997"
						$matchcount=preg_match('/(\d\d\d\d)/',$field->subfields['c'], $matches);
						if ($matchcount>0) {
							$temp = $matches[1];
						}else {
							//match for mingguo year pattern (in traditional chinese character)
							$matchcount=preg_match('/\xE6\xB0\x91\xE5\x9C\x8B(\d{2})/',$field->subfields['c'], $matches);
							if ($matchcount>0) {
								$temp = strval(intval($matches[1])+1911);
							} else {
								//match for mingguo year pattern (in simplified chinese character)
								$matchcount=preg_match('/\xE6\xB0\x91\xE5\x9B\xBD(\d{2})/',$field->subfields['c'], $matches);
								if ($matchcount>0) {
									$temp = strval(intval($matches[1])+1911);
								}
							}
						}
						if ($temp) {
							$atomic['published'][0]['cy'][] = $temp;
						}
					}
				}else if($field->tagno == 5){
					$_acqdate[] = $line{7}.$line{8}.$line{9}.$line{10} .'-'. $line{11}.$line{12} .'-'. $line{13}.$line{14};
				}else if($field->tagno == 8){
					$temp = intval(substr($line, 14, 4));
					if($temp)
						$atomic['published'][0]['cy'][] = preg_replace('/[^\d]/', '0' ,substr($line, 14, 4));
				
				//Subjects
				// tag 600 - Person
				}else if($field->tagno == '600'){
	
					$subjtemp = array();
					foreach( array_map( array( 'scrib', 'meditor_sanitize_punctuation' ), $field->subfields ) as $key => $val ){
						switch( $key[0] ){
							case 'a':
							case 'q':
								$subjtemp[] = array( 'type' => 'person', 'val' => $val );
								break;
	
							case 'v':
								$subjtemp[] = array( 'type' => 'genre', 'val' => $val );
								break;
	
							case 'x':
								$subjtemp[] = array( 'type' => 'subject', 'val' => $val );
								break;
	
							case 'd':
							case 'y':
								$subjtemp[] = array( 'type' => 'time', 'val' => $val );
								break;
	
							case 'z':
								$subjtemp[] = array( 'type' => 'place', 'val' => $val );
								break;
						}
					}
	
				// tag 648 - Time
				}else if($field->tagno == '648'){

					$subjtemp = array();
					foreach( array_map( array( 'scrib', 'meditor_sanitize_punctuation' ), $field->subfields ) as $key => $val ){
						switch( $key[0] ){
							case 'a':
							case 'y':
								$subjtemp[] = array( 'type' => 'time', 'val' => $val );
								break;
	
							case 'v':
								$subjtemp[] = array( 'type' => 'genre', 'val' => $val );
								break;
	
							case 'x':
								$subjtemp[] = array( 'type' => 'subject', 'val' => $val );
								break;
	
							case 'z':
								$subjtemp[] = array( 'type' => 'place', 'val' => $val );
								break;
						}
					}
	
				// tag 650 - Topical Terms
				}else if( $field->tagno == '650' ){
					if( 6 == $line[5] )
						continue;

					$subjtemp = array();
					foreach( array_map( array( 'scrib', 'meditor_sanitize_punctuation' ), $field->subfields ) as $key => $val ){
						switch( $key[0] ){
							case 'a':
							case 'b':
							case 'x':
								$subjtemp[] = array( 'type' => 'subject', 'val' => $val );
								break;
	
							case 'c':
							case 'z':
								$subjtemp[] = array( 'type' => 'place', 'val' => $val );
								break;
	
							case 'd':
							case 'y':
								$subjtemp[] = array( 'type' => 'time', 'val' => $val );
								break;
	
							case 'v':
								$subjtemp[] = array( 'type' => 'genre', 'val' => $val );
								break;
						}
					}
	
				// tag 651 - Geography
				}else if($field->tagno == '651'){
	
					$subjtemp = array();
					foreach( array_map( array( 'scrib', 'meditor_sanitize_punctuation' ), $field->subfields ) as $key => $val ){
						switch( $key[0] ){
							case 'a':
							case 'z':
								$subjtemp[] = array( 'type' => 'place', 'val' => $val );
								break;
	
							case 'v':
								$subjtemp[] = array( 'type' => 'genre', 'val' => $val );
								break;
	
							case 'e':
							case 'x':
								$subjtemp[] = array( 'type' => 'subject', 'val' => $val );
								break;
	
							case 'y':
								$subjtemp[] = array( 'type' => 'time', 'val' => $val );
								break;
	
							case 'z':
								$subjtemp[] = array( 'type' => 'place', 'val' => $val );
								break;
						}
					}
	
				// tag 654 - Topical Terms
				}else if($field->tagno == '654'){
	
					$subjtemp = array();
					foreach( array_map( array( 'scrib', 'meditor_sanitize_punctuation' ), $field->subfields ) as $key => $val ){
						switch( $key[0] ){
							case 'a':
							case 'b':
							case 'c':
							case 'd':
							case 'f':
							case 'g':
							case 'h':
								$subjtemp[] = array( 'type' => 'subject', 'val' => $val );
								break;
	
							case 'y':
								$subjtemp[] = array( 'type' => 'time', 'val' => $val );
								break;
	
							case 'z':
								$subjtemp[] = array( 'type' => 'place', 'val' => $val );
								break;
						}
					}
	
				// tag 655 - Genre
				}else if($field->tagno == '655'){

					$subjtemp = array();
					foreach( array_map( array( 'scrib', 'meditor_sanitize_punctuation' ), $field->subfields ) as $key => $val ){
						switch( $key[0] ){
							case 'a':
							case 'b':
							case 'c':
							case 'v':
								$subjtemp[] = array( 'type' => 'subject', 'val' => $val );
								break;
	
							case 'x':
								$subjtemp[] = array( 'type' => 'subject', 'val' => $val );
								break;
	
							case 'y':
								$subjtemp[] = array( 'type' => 'time', 'val' => $val );
								break;
	
							case 'z':
								$subjtemp[] = array( 'type' => 'place', 'val' => $val );
								break;
						}
					}
	
				// tag 662 - Geography
				}else if($field->tagno == '662'){

					$subjtemp = array();
					foreach( array_map( array( 'scrib', 'meditor_sanitize_punctuation' ), $field->subfields ) as $key => $val ){
						switch( $key[0] ){
							case 'a':
							case 'b':
							case 'c':
							case 'd':
							case 'f':
							case 'g':
							case 'h':
								$subjtemp[] = array( 'type' => 'place', 'val' => $val );
								break;
	
							case 'e':
								$subjtemp[] = array( 'type' => 'subject', 'val' => $val );
								break;
						}
					}
	
				// everything else
				}else if(($field->tagno > 599) && ($field->tagno < 700)){
					if( 6 == $line[5] )
						continue;

					$subjtemp = array();
					foreach( array_map( array( 'scrib', 'meditor_sanitize_punctuation' ), $field->subfields ) as $key => $val ){
						switch( $key[0] ){
							case 'a':
							case 'b':
							case 'x':
								$subjtemp[] = array( 'type' => 'subject', 'val' => $val );
								break;
	
							case 'v':
							case 'k':
								$subjtemp[] = array( 'type' => 'genre', 'val' => $val );
								break;
	
							case 'y':
								$subjtemp[] = array( 'type' => 'time', 'val' => $val );
								break;
	
							case 'z':
								$subjtemp[] = array( 'type' => 'place', 'val' => $val );
								break;
						}
					}
	
	
				//URLs
				}else if($field->tagno == 856){
					$temp['href'] = $temp['title'] = str_replace(' ', '', $field->subfields['u']);
					$temp['title'] = trim( parse_url( $temp['href'] , PHP_URL_HOST ), 'www.' );
					if($field->subfields['3'])
						$temp['title'] = $field->subfields['3'];
					if($field->subfields['z'])
						$temp['title'] = $field->subfields['z'];
					$atomic['linked_urls'][] = array( 'name' => $temp['title'], 'href' => $temp['href'] );
	
				//Notes
	//			}else if(($field->tagno > 299) && ($field->tagno < 400)){
	//				$atomic['physdesc'][] = implode(' ', array_values($marc));
	
				}else if(($field->tagno > 399) && ($field->tagno < 490)){
					$atomic['alttitle'][] = array( 'a' => $scrib->meditor_sanitize_punctuation( implode(' ', array_values( $marc ))));
	
				}else if(($field->tagno > 799) && ($field->tagno < 841)){
					$atomic['alttitle'][] = array( 'a' => $scrib->meditor_sanitize_punctuation( implode(' ', array_values( $marc ))));
	
				}else if(($field->tagno > 499) && ($field->tagno < 600)){
					$line = implode( "\n", array_values( $field->subfields ));
					if($field->tagno == 504)
						continue;
					if($field->tagno == 505){
						$atomic['text'][] = array( 'type' => 'contents', 'content' => ( '<ul><li>'. implode( "</li>\n<li>", array_map( array( 'scrib', 'meditor_sanitize_punctuation' ), explode( '--', str_replace( array( '|t', '|r' , '|g' ), ' ', preg_replace( '/-[\s]+-/', '--', $line )))) ) .'</li></ul>' ));
						continue;
					}
	
					//strip the subfield delimiter and codes
					$atomic['text'][] = array( 'type' => 'notes', 'content' => $scrib->meditor_sanitize_punctuation( $line ));
				}
				
	
				// pick up the subjects parsed above
				if( count( $subjtemp )){
					$temp = array();
					foreach( $subjtemp as $key => $val ){
						$temp[ $spare_keys[ $key ] .'_type' ] = $val['type']; 
						$temp[ $spare_keys[ $key ] ] = $val['val']; 
					}
					$atomic['subject'][] = $temp;
				}
	
				//Format
				if(($field->tagno > 239) && ($field->tagno < 246)){
					$temp = ucwords(strtolower(str_replace('[', '', str_replace(']', '', $field->subfields['h']))));
					
					if(eregi('^book', $temp)){
						$atomic['format'][] = array( 'a' => 'Book' );
	
					}else if(eregi('^micr', $temp)){
						$atomic['format'][] = array( 'a' => 'Microform' );
	
					}else if(eregi('^electr', $temp)){
						$atomic['format'][] = array( 'a' => 'E-Resource' );
	
					}else if(eregi('^vid', $temp)){
						$atomic['format'][] = array( 'a' => 'Video' );
					}else if(eregi('^motion', $temp)){
						$atomic['format'][] = array( 'a' => 'Video' );
	
					}else if(eregi('^audi', $temp)){
						$atomic['format'][] = array( 'a' => 'Audio' );
						$format = 'Audio';
					}else if(eregi('^cass', $temp)){
						$atomic['format'][] = array( 'a' => 'Audio', 'b' => 'Cassette' );
					}else if(eregi('^phono', $temp)){
						$atomic['format'][] = array( 'a' => 'Audio', 'b' => 'Phonograph' );
					}else if(eregi('^record', $temp)){
						$atomic['format'][] = array( 'a' => 'Audio', 'b' => 'Phonograph' );
					}else if(eregi('^sound', $temp)){
						$atomic['format'][] = array( 'a' => 'Audio' );
	
					}else if(eregi('^carto', $temp)){
						$atomic['format'][] = array( 'a' => 'Map' );
					}else if(eregi('^map', $temp)){
						$atomic['format'][] = array( 'a' => 'Map' );
					}else if(eregi('^globe', $temp)){
						$atomic['format'][] = array( 'a' => 'Map' );
					}
				}
	
				if($field->tagno == '008' && (substr($lineray[2], 22,1) == 'p' || substr($lineray[2], 22,1) == 'n')){
					$atomic['format'][] = array( 'a' => 'Journal' );
				}
			}
		}
		// end the big loop


		// sanity check the pubyear
		foreach( array_filter( array_unique( $atomic['published'][0]['cy'] )) as $key => $temp )
			if( $temp > date('Y') + 2 )
				unset( $atomic['published'][0]['cy'][$key] );
		$atomic['published'][0]['cy'] = array_shift( $atomic['published'][0]['cy'] );
		if( empty( $atomic['published'][0]['cy'] ))
			$atomic['published'][0]['cy'] = date('Y') - 1;


		if(!$atomic['format'][0])
			$atomic['format'][0] = array( 'a' => 'Book' );

		if( $atomic['alttitle'] ){
			$atomic['title'] = array_merge( $atomic['title'], $atomic['alttitle'] );
			unset( $atomic['alttitle'] );
		}

		// clean up published
		if( isset( $atomic['published'][0]['lang'] ))
			$atomic['published'][0]['lang'] = array_shift( array_filter( $atomic['published'][0]['lang'] ));
		if( isset( $atomic['published'][0]['publisher'] ))
			$atomic['published'][0]['publisher'] = array_shift( array_filter( $atomic['published'][0]['publisher'] ));

		// unique the values
		foreach( $atomic as $key => $val )
			$atomic[ $key ] = $scrib->array_unique_deep( $atomic[ $key ] );

		// possibly capitalize titles
		if( $prefs['capitalize_titles'] )
			foreach( $atomic['title'] as $key => $val )
				$atomic['title'][ $key ]['a'] = ucwords( $val['a'] );

		// insert the sourceid
		$_sourceid = $prefs['sourceprefix'] . $bibn;
		$atomic['idnumbers'][] = array( 'type' => 'sourceid', 'id' => $_sourceid );

		// sanity check the _acqdate
		$_acqdate = array_unique($_acqdate);
		foreach( $_acqdate as $key => $temp )
			if( strtotime( $temp ) > strtotime( date('Y') + 2 ))
				unset( $_acqdate[$key] );
		$_acqdate = array_values( $_acqdate );
		if( !isset( $_acqdate[0] ))
			if( isset( $atomic['pubyear'][0] ))
				$_acqdate[0] = $atomic['pubyear'][0] .'-01-01';
			else
				$_acqdate[0] = ( date('Y') - 1 ) .'-01-01';
		$_acqdate = $_acqdate[0];

		if( !empty( $atomic['title'] ) && !empty( $_sourceid )){
			foreach( $atomic as $ak => $av )
				foreach( $av as $bk => $bv )
					if( is_array( $bv ))
						$atomic[ $ak ][ $bk ] = array_merge( $bv, array( 'src' => 'sourceid:'. $_sourceid ));

			$atomic = array( 'marcish' => $atomic );
			$atomic['_acqdate'] = $_acqdate;
			$atomic['_sourceid'] = $_sourceid;
			$atomic['_title'] = $atomic['marcish']['title'][0]['a'];
			$atomic['_idnumbers'] = $atomic['marcish']['idnumbers'];

			$scrib->import_insert_harvest( $atomic );
			return( $atomic );
		}else{
			$this->error = 'Record number '. $bibn .' couldn&#039;t be parsed.';
			return(FALSE);
		}
	}

	function done(){
		$prefs = get_option('scrib_marcimporter');

		// click next
		echo '<div class="narrow">';

		if(count($prefs['scrib_marc-warnings'])){
			echo '<h3 id="warnings">Warnings</h3>';
			echo '<a href="#complete">bottom</a> &middot; <a href="#errors">errors</a>';
			echo '<ol><li>';
			echo implode($prefs['scrib_marc-warnings'], '</li><li>');
			echo '</li></ol>';
		}

		if(count($prefs['scrib_marc-errors'])){
			echo '<h3 id="errors">Errors</h3>';
			echo '<a href="#complete">bottom</a> &middot; <a href="#warnings">warnings</a>';
			echo '<ol><li>';
			echo implode($prefs['scrib_marc-errors'], '</li><li>');
			echo '</li></ol>';
		}		

		echo '<h3 id="complete">'.__('Processing complete.').'</h3>';
		echo '<p>'. $prefs['scrib_marc-records_harvested'] .' of '. $prefs['scrib_marc-records_count'] .' '.__('records harvested.').' with '. count($prefs['scrib_marc-warnings']) .' <a href="#warnings">warnings</a> and '. count($prefs['scrib_marc-errors']) .' <a href="#errors">errors</a>.</p>';
		echo '</div>';
	}

	// Default constructor
	function Scrib_marc() {
		global $wpdb;

		$this->harvest_table = $wpdb->prefix . 'scrib_harvest';

		register_taxonomy( 'sourceid', 'post' );
	}
}

// Instantiate and register the importer
include_once(ABSPATH . 'wp-admin/includes/import.php');
if(function_exists('register_importer')) {
	$scrib_marc = new Scrib_marc();
	register_importer($scrib_marc->importer_code, $scrib_marc->importer_name, $scrib_marc->importer_desc, array (&$scrib_marc, 'dispatch'));
}
?>
