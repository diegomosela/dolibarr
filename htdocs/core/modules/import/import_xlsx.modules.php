<?php
/* Copyright (C) 2006-2012	Laurent Destailleur	<eldy@users.sourceforge.net>
 * Copyright (C) 2009-2012	Regis Houssin		<regis.houssin@capnetworks.com>
 * Copyright (C) 2012      Christophe Battarel  <christophe.battarel@altairis.fr>
 * Copyright (C) 2012       Juanjo Menent		<jmenent@2byte.es>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * or see http://www.gnu.org/
 */

/**
 *		\file       htdocs/core/modules/import/import_csv.modules.php
 *		\ingroup    import
 *		\brief      File to load import files with CSV format
 */

require_once DOL_DOCUMENT_ROOT .'/core/modules/import/modules_import.php';


/**
 *	Class to import Excel files
 */
class Importxlsx extends ModeleImports
{
    var $db;
    var $datatoimport;

	var $error='';
	var $errors=array();

    var $id;           // Id of driver
	var $label;        // Label of driver
	var $extension;    // Extension of files imported by driver
	var $version;      // Version of driver

	var $label_lib;    // Label of external lib used by driver
	var $version_lib;  // Version of external lib used by driver

	var $separator;

	var $handle;    // Handle fichier

	var $cacheconvert=array();      // Array to cache list of value found after a convertion
	var $cachefieldtable=array();   // Array to cache list of value found into fields@tables
  
	var $workbook; // temporary import file
	var $record; // current record
	var $headers; 


	/**
	 *	Constructor
	 *
	 *	@param	DoliDB		$db				Database handler
	 *	@param	string		$datatoimport	String code describing import set (ex: 'societe_1')
	 */
	function __construct($db,$datatoimport)
	{
		global $conf,$langs;
		$this->db = $db;

		// this is used as an extension from the example file code, so we have to put xlsx here !!!
		$this->id='xlsx';                // Same value as xxx in file name export_xxx.modules.php
		$this->label='Excel';             // Label of driver
		$this->desc=$langs->trans("ExcelFormatDesc",$this->separator,$this->enclosure,$this->escape);
		$this->extension='xlsx';         // Extension for generated file by this driver
		$this->picto='mime/xls';		// Picto (This is not used by the example file code as Mime type, too bad ...)
		$this->version='1.0';         // Driver version

		// If driver use an external library, put its name here
    require_once PHPEXCEL_PATH.'PHPExcel.php';
		require_once PHPEXCEL_PATH.'PHPExcel/Style/Alignment.php';
              if (! class_exists('ZipArchive')) // For Excel2007, PHPExcel need ZipArchive
              {
                $langs->load("errors");
                $this->error=$langs->trans('ErrorPHPNeedModule','zip');
                return -1;
              }
    $this->label_lib='PhpExcel';
    $this->version_lib='1.8.0';

		$this->datatoimport=$datatoimport;
		if (preg_match('/^societe_/',$datatoimport)) $this->thirpartyobject=new Societe($this->db);
	}

// Those Get methods should all be in the parent class !!! 
	/**
	 * getDriverId
	 *
	 * @return string		Id
	 */
	function getDriverId()
	{
		return $this->id;
	}

	/**
	 *	getDriverLabel
	 *
	 *	@return string	Label
	 */
	function getDriverLabel()
	{
		return $this->label;
	}

	/**
	 *	getDriverDesc
	 *
	 *	@return string	Description
	 */
	function getDriverDesc()
	{
		return $this->desc;
	}

	/**
	 * getDriverExtension
	 *
	 * @return string	Driver suffix
	 */
	function getDriverExtension()
	{
		return $this->extension;
	}

	/**
	 *	getDriverVersion
	 *
	 *	@return string	Driver version
	 */
	function getDriverVersion()
	{
		return $this->version;
	}

	/**
	 *	getDriverLabel
	 *
	 *	@return string	Label of external lib
	 */
	function getLibLabel()
	{
		return $this->label_lib;
	}

	/**
	 * getLibVersion
	 *
	 *	@return string	Version of external lib
	 */
	function getLibVersion()
	{
		return $this->version_lib;
	}


	/**
	 * 	Output header of an example file for this format
	 *
	 * 	@param	Translate	$outputlangs		Output language
	 *  @return	string
	 */
	function write_header_example($outputlangs)
	{
	  global $user,$conf,$langs;
	  // create a temporary object, the final output will be generated in footer
          if (!empty($conf->global->MAIN_USE_FILECACHE_EXPORT_EXCEL_DIR)) {
            $cacheMethod = PHPExcel_CachedObjectStorageFactory::cache_to_discISAM;
            $cacheSettings = array (
              'dir' => $conf->global->MAIN_USE_FILECACHE_EXPORT_EXCEL_DIR
          );
          PHPExcel_Settings::setCacheStorageMethod($cacheMethod, $cacheSettings);
        }

            $this->workbook = new PHPExcel();
            $this->workbook->getProperties()->setCreator($user->getFullName($outputlangs).' - Dolibarr '.DOL_VERSION);
            $this->workbook->getProperties()->setTitle($outputlangs->trans("Import").' - '.$file);
            $this->workbook->getProperties()->setSubject($outputlangs->trans("Import").' - '.$file);
            $this->workbook->getProperties()->setDescription($outputlangs->trans("Import").' - '.$file);

            $this->workbook->setActiveSheetIndex(0);
            $this->workbook->getActiveSheet()->setTitle($outputlangs->trans("Sheet"));
            $this->workbook->getActiveSheet()->getDefaultRowDimension()->setRowHeight(16);

	    return '';
	}

	/**
	 * 	Output title line of an example file for this format
	 *
	 * 	@param	Translate	$outputlangs		Output language
	 *  @param	array		$headerlinefields	Array of fields name
	 * 	@return	string
	 */
	function write_title_example($outputlangs,$headerlinefields)
	{
    global $conf;
    $this->workbook->getActiveSheet()->getStyle('1')->getFont()->setBold(true);
    $this->workbook->getActiveSheet()->getStyle('1')->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_LEFT);

    $col = 0;
    foreach($headerlinefields as $field) {
      $this->workbook->getActiveSheet()->SetCellValueByColumnAndRow($col, 1, $outputlangs->transnoentities($field));
      // set autowidth
      //$this->workbook->getActiveSheet()->getColumnDimension($this->column2Letter($col + 1))->setAutoSize(true); 
      $col++;
    }
		return ''; // final output will be generated in footer
	}

	/**
	 * 	Output record of an example file for this format
	 *
	 * 	@param	Translate	$outputlangs		Output language
	 * 	@param	array		$contentlinevalues	Array of lines
	 * 	@return	string
	 */
	function write_record_example($outputlangs,$contentlinevalues)
	{
    $col = 0;
    $row = 2;
    foreach($contentlinevalues as $cell) {
        $this->workbook->getActiveSheet()->SetCellValueByColumnAndRow($col, $row, $cell);
        $col++;
    }
    return ''; // final output will be generated in footer
	}

	/**
	 * 	Output footer of an example file for this format
	 *
	 * 	@param	Translate	$outputlangs		Output language
	 *  @return	string
	 */
	function write_footer_example($outputlangs)
	{
		// return te file content as a string
    $tempfile = tempnam(sys_get_temp_dir(), 'dol');
    $objWriter = new PHPExcel_Writer_Excel2007($this->workbook);
    $objWriter->save($tempfile);
    $this->workbook->disconnectWorksheets();
    unset($this->workbook);

    $content = file_get_contents($tempfile);
    unlink($tempfile);
    return $content;
	}



	/**
	 *	Open input file
	 *
	 *	@param	string	$file		Path of filename
	 *	@return	int					<0 if KO, >=0 if OK
	 */
	function import_open_file($file)
	{
		global $langs;
		$ret=1;

		dol_syslog(get_class($this)."::open_file file=".$file);

		$reader = new PHPExcel_Reader_Excel2007();
		$this->workbook = $reader->load($file);
		$this->record = 1;
		return $ret;
	}

	/**
	 * 	Input header line from file
	 *
	 * 	@return		int		<0 if KO, >=0 if OK
	 */
	function import_read_header()
	{
		// This is not called by the import code !!!
		$this->headers = array();
		$colcount = PHPExcel_Cell::columnIndexFromString($this->workbook->getActiveSheet()->getHighestDataColumn());
		for($col=0;$col<$colcount;$col++) {
			$this->headers[$col] = $this->workbook->getActiveSheet()->getCellByColumnAndRow($col, 1)->getValue();
		}
		return 0;
	}


	/**
	 * 	Return array of next record in input file.
	 *
	 * 	@return		Array		Array of field values. Data are UTF8 encoded. [fieldpos] => (['val']=>val, ['type']=>-1=null,0=blank,1=not empty string)
	 */
	function import_read_record()
	{
		global $conf;

		$rowcount = $this->workbook->getActiveSheet()->getHighestDataRow();
		if($this->record > $rowcount)
			return false;
		$array = array();
		$colcount = PHPExcel_Cell::columnIndexFromString($this->workbook->getActiveSheet()->getHighestDataColumn(0));
		for($col=0;$col<$colcount;$col++) {
			$val = $this->workbook->getActiveSheet()->getCellByColumnAndRow($col, $this->record)->getValue();
			$array[$col]['val'] = $val;
			$array[$col]['type'] = (dol_strlen($val)?1:-1); // If empty we consider it null
		}
		$this->record++;
		return $array;
	}

	/**
	 * 	Close file handle
	 *
	 *  @return	integer
	 */
	function import_close_file()
	{
		$this->workbook->disconnectWorksheets();
		unset($this->workbook);
	}


	/**
	 * Insert a record into database
	 *
	 * @param	array	$arrayrecord					Array of read values: [fieldpos] => (['val']=>val, ['type']=>-1=null,0=blank,1=string), [fieldpos+1]...
	 * @param	array	$array_match_file_to_database	Array of target fields where to insert data: [fieldpos] => 's.fieldname', [fieldpos+1]...
	 * @param 	Object	$objimport						Object import (contains objimport->import_tables_array, objimport->import_fields_array, objimport->import_convertvalue_array, ...)
	 * @param	int		$maxfields						Max number of fields to use
	 * @param	string	$importid						Import key
	 * @return	int										<0 if KO, >0 if OK
	 */
	// What is this doing here ? it is common to all imports, is should be in the parent class
	function import_insert($arrayrecord,$array_match_file_to_database,$objimport,$maxfields,$importid)
	{
		global $langs,$conf,$user;
        global $thirdparty_static;    	// Specific to thirdparty import
		global $tablewithentity_cache;	// Cache to avoid to call  desc at each rows on tables

		$error=0;
		$warning=0;
		$this->errors=array();
		$this->warnings=array();

		//dol_syslog("import_csv.modules maxfields=".$maxfields." importid=".$importid);

		//var_dump($array_match_file_to_database);
		//var_dump($arrayrecord);
		$array_match_database_to_file=array_flip($array_match_file_to_database);
		$sort_array_match_file_to_database=$array_match_file_to_database;
		ksort($sort_array_match_file_to_database);

		//var_dump($sort_array_match_file_to_database);

		if (count($arrayrecord) == 0 || (count($arrayrecord) == 1 && empty($arrayrecord[0]['val'])))
		{
			//print 'W';
			$this->warnings[$warning]['lib']=$langs->trans('EmptyLine');
			$this->warnings[$warning]['type']='EMPTY';
			$warning++;
		}
		else
		{
			$last_insert_id_array = array(); // store the last inserted auto_increment id for each table, so that dependent tables can be inserted with the appropriate id (eg: extrafields fk_object will be set with the last inserted object's id)
			// For each table to insert, me make a separate insert
			foreach($objimport->array_import_tables[0] as $alias => $tablename)
			{
				// Build sql request
				$sql='';
				$listfields='';
				$listvalues='';
				$i=0;
				$errorforthistable=0;

				// Define $tablewithentity_cache[$tablename] if not already defined
				if (! isset($tablewithentity_cache[$tablename]))	// keep this test with "isset"
				{
					dol_syslog("Check if table ".$tablename." has an entity field");
					$resql=$this->db->DDLDescTable($tablename,'entity');
					if ($resql)
					{
						$obj=$this->db->fetch_object($resql);
						if ($obj) $tablewithentity_cache[$tablename]=1;		// table contains entity field
						else $tablewithentity_cache[$tablename]=0;			// table does not contains entity field
					}
					else dol_print_error($this->db);
				}
				else
				{
					//dol_syslog("Table ".$tablename." check for entity into cache is ".$tablewithentity_cache[$tablename]);
				}


				// Loop on each fields in the match array: $key = 1..n, $val=alias of field (s.nom)
				foreach($sort_array_match_file_to_database as $key => $val)
				{
				    $fieldalias=preg_replace('/\..*$/i','',$val);
				    $fieldname=preg_replace('/^.*\./i','',$val);

				    if ($alias != $fieldalias) continue;    // Not a field of current table

					if ($key <= $maxfields)
					{
						// Set $newval with value to insert and set $listvalues with sql request part for insert
						$newval='';
						if ($arrayrecord[($key-1)]['type'] > 0) $newval=$arrayrecord[($key-1)]['val'];    // If type of field into input file is not empty string (so defined into input file), we get value

						// Make some tests on $newval

						// Is it a required field ?
						if (preg_match('/\*/',$objimport->array_import_fields[0][$val]) && ($newval==''))
						{
							$this->errors[$error]['lib']=$langs->trans('ErrorMissingMandatoryValue',$key);
							$this->errors[$error]['type']='NOTNULL';
							$errorforthistable++;
							$error++;
						}
						// Test format only if field is not a missing mandatory field (field may be a value or empty but not mandatory)
						else
						{
						    // We convert field if required
						    if (! empty($objimport->array_import_convertvalue[0][$val]))
						    {
                                //print 'Must convert '.$newval.' with rule '.join(',',$objimport->array_import_convertvalue[0][$val]).'. ';
                                if ($objimport->array_import_convertvalue[0][$val]['rule']=='fetchidfromcodeid'
                                	|| $objimport->array_import_convertvalue[0][$val]['rule']=='fetchidfromref'
                                	|| $objimport->array_import_convertvalue[0][$val]['rule']=='fetchidfromcodeorlabel'
                                	)
                                {
                                    if (! is_numeric($newval) && $newval != '')    // If value into input import file is not a numeric, we apply the function defined into descriptor
                                    {
                                        $file=$objimport->array_import_convertvalue[0][$val]['classfile'];
                                        $class=$objimport->array_import_convertvalue[0][$val]['class'];
                                        $method=$objimport->array_import_convertvalue[0][$val]['method'];
                                        if ($this->cacheconvert[$file.'_'.$class.'_'.$method.'_'][$newval] != '')
                                        {
                                        	$newval=$this->cacheconvert[$file.'_'.$class.'_'.$method.'_'][$newval];
                                        }
                                        else
										{
                                            dol_include_once($file);
                                            $classinstance=new $class($this->db);
                                            // Try the fetch from code or ref
                                            call_user_func_array(array($classinstance, $method),array('', $newval));
                                            // If not found, try the fetch from label
                                            if (! ($classinstance->id != '') && $objimport->array_import_convertvalue[0][$val]['rule']=='fetchidfromcodeorlabel')
                                            {
												call_user_func_array(array($classinstance, $method),array('', '', $newval));
                                            }
                                            $this->cacheconvert[$file.'_'.$class.'_'.$method.'_'][$newval]=$classinstance->id;
                                            //print 'We have made a '.$class.'->'.$method.' to get id from code '.$newval.'. ';
                                            if ($classinstance->id != '')	// id may be 0, it is a found value
                                            {
                                                $newval=$classinstance->id;
                                            }
                                            else
                                            {
                                                if (!empty($objimport->array_import_convertvalue[0][$val]['dict'])) $this->errors[$error]['lib']=$langs->trans('ErrorFieldValueNotIn',$key,$newval,'code',$langs->transnoentitiesnoconv($objimport->array_import_convertvalue[0][$val]['dict']));
                                                else if (!empty($objimport->array_import_convertvalue[0][$val]['element'])) $this->errors[$error]['lib']=$langs->trans('ErrorFieldRefNotIn',$key,$newval,$langs->transnoentitiesnoconv($objimport->array_import_convertvalue[0][$val]['element']));
                                                else $this->errors[$error]['lib']='ErrorFieldValueNotIn';
                                                $this->errors[$error]['type']='FOREIGNKEY';
                                                $errorforthistable++;
                                                $error++;
                                            }
                                        }
                                    }

                                }
                                elseif ($objimport->array_import_convertvalue[0][$val]['rule']=='zeroifnull')
                                {
                                    if (empty($newval)) $newval='0';
                                }
                                elseif ($objimport->array_import_convertvalue[0][$val]['rule']=='getcustomercodeifauto')
                                {
                                    if (strtolower($newval) == 'auto')
                                    {
                                        $this->thirpartyobject->get_codeclient(0,0);
                                        $newval=$this->thirpartyobject->code_client;
                                        //print 'code_client='.$newval;
                                    }
                                    if (empty($newval)) $arrayrecord[($key-1)]['type']=-1;	// If we get empty value, we will use "null"
                                }
                                elseif ($objimport->array_import_convertvalue[0][$val]['rule']=='getsuppliercodeifauto')
                                {
                                    if (strtolower($newval) == 'auto')
                                    {
                                        $newval=$this->thirpartyobject->get_codefournisseur(0,1);
                                        $newval=$this->thirpartyobject->code_fournisseur;
                                        //print 'code_fournisseur='.$newval;
                                    }
                                    if (empty($newval)) $arrayrecord[($key-1)]['type']=-1;	// If we get empty value, we will use "null"
                                }
                                elseif ($objimport->array_import_convertvalue[0][$val]['rule']=='getcustomeraccountancycodeifauto')
                                {
                                    if (strtolower($newval) == 'auto')
                                    {
                                        $this->thirpartyobject->get_codecompta('customer');
                                        $newval=$this->thirpartyobject->code_compta;
                                        //print 'code_compta='.$newval;
                                    }
                                    if (empty($newval)) $arrayrecord[($key-1)]['type']=-1;	// If we get empty value, we will use "null"
                                }
                                elseif ($objimport->array_import_convertvalue[0][$val]['rule']=='getsupplieraccountancycodeifauto')
                                {
                                    if (strtolower($newval) == 'auto')
                                    {
                                        $this->thirpartyobject->get_codecompta('supplier');
                                        $newval=$this->thirpartyobject->code_compta_fournisseur;
                                        if (empty($newval)) $arrayrecord[($key-1)]['type']=-1;	// If we get empty value, we will use "null"
                                        //print 'code_compta_fournisseur='.$newval;
                                    }
                                    if (empty($newval)) $arrayrecord[($key-1)]['type']=-1;	// If we get empty value, we will use "null"
                                }

                                //print 'Val to use as insert is '.$newval.'<br>';
						    }

						    // Test regexp
							if (! empty($objimport->array_import_regex[0][$val]) && ($newval != ''))
							{
								// If test is "Must exist in a field@table"
								if (preg_match('/^(.*)@(.*)$/',$objimport->array_import_regex[0][$val],$reg))
								{
									$field=$reg[1];
									$table=$reg[2];

									// Load content of field@table into cache array
									if (! is_array($this->cachefieldtable[$field.'@'.$table])) // If content of field@table not already loaded into cache
									{
										$sql="SELECT ".$field." as aliasfield FROM ".$table;
										$resql=$this->db->query($sql);
										if ($resql)
										{
											$num=$this->db->num_rows($resql);
											$i=0;
											while ($i < $num)
											{
												$obj=$this->db->fetch_object($resql);
												if ($obj) $this->cachefieldtable[$field.'@'.$table][]=$obj->aliasfield;
												$i++;
											}
										}
										else
										{
											dol_print_error($this->db);
										}
									}

									// Now we check cache is not empty (should not) and key is into cache
									if (! is_array($this->cachefieldtable[$field.'@'.$table]) || ! in_array($newval,$this->cachefieldtable[$field.'@'.$table]))
									{
										$this->errors[$error]['lib']=$langs->transnoentitiesnoconv('ErrorFieldValueNotIn',$key,$newval,$field,$table);
										$this->errors[$error]['type']='FOREIGNKEY';
									    $errorforthistable++;
										$error++;
									}
								}
								// If test is just a static regex
								else if (! preg_match('/'.$objimport->array_import_regex[0][$val].'/i',$newval))
								{
								    //if ($key == 19) print "xxx".$newval."zzz".$objimport->array_import_regex[0][$val]."<br>";
									$this->errors[$error]['lib']=$langs->transnoentitiesnoconv('ErrorWrongValueForField',$key,$newval,$objimport->array_import_regex[0][$val]);
									$this->errors[$error]['type']='REGEX';
									$errorforthistable++;
									$error++;
								}
							}

							// Other tests
							// ...
						}

						// Define $listfields and $listvalues to build SQL request
						if ($listfields) { $listfields.=', '; $listvalues.=', '; }
						$listfields.=$fieldname;

						// Note: arrayrecord (and 'type') is filled with ->import_read_record called by import.php page before calling import_insert
						if (empty($newval) && $arrayrecord[($key-1)]['type'] < 0)       $listvalues.=($newval=='0'?$newval:"null");
						elseif (empty($newval) && $arrayrecord[($key-1)]['type'] == 0) $listvalues.="''";
						else															 $listvalues.="'".$this->db->escape($newval)."'";
					}
					$i++;
				}

				// We add hidden fields (but only if there is at least one field to add into table)
				if ($listfields && is_array($objimport->array_import_fieldshidden[0]))
				{
    				// Loop on each hidden fields to add them into listfields/listvalues
				    foreach($objimport->array_import_fieldshidden[0] as $key => $val)
    				{
    				    if (! preg_match('/^'.preg_quote($alias).'\./', $key)) continue;    // Not a field of current table
    				    if ($listfields) { $listfields.=', '; $listvalues.=', '; }
    				    if ($val == 'user->id')
    				    {
    				        $listfields.=preg_replace('/^'.preg_quote($alias).'\./','',$key);
    				        $listvalues.=$user->id;
    				    }
    				    elseif (preg_match('/^lastrowid-/',$val))
    				    {
    				        $tmp=explode('-',$val);
    				        $lastinsertid=(isset($last_insert_id_array[$tmp[1]]))?$last_insert_id_array[$tmp[1]]:0;
    				        $listfields.=preg_replace('/^'.preg_quote($alias).'\./','',$key);
                            $listvalues.=$lastinsertid;
    				        //print $key."-".$val."-".$listfields."-".$listvalues."<br>";exit;
    				    } else 
				    {
					 $listfields.=preg_replace('/^'.preg_quote($alias).'\./','',$key);
					 $listvalues.=$val;
				    }
    				}
				}
				//print 'listfields='.$listfields.'<br>listvalues='.$listvalues.'<br>';

				// If no error for this $alias/$tablename, we have a complete $listfields and $listvalues that are defined
				if (! $errorforthistable)
				{
				    //print "$alias/$tablename/$listfields/$listvalues<br>";
					if ($listfields)
					{
					    //var_dump($objimport->array_import_convertvalue); exit;

						// Build SQL request
						if (empty($tablewithentity_cache[$tablename]))
						{
							$sql ='INSERT INTO '.$tablename.'('.$listfields.', import_key';
							if (! empty($objimport->array_import_tables_creator[0][$alias])) $sql.=', '.$objimport->array_import_tables_creator[0][$alias];
							$sql.=') VALUES('.$listvalues.", '".$importid."'";
						}
						else
						{
							$sql ='INSERT INTO '.$tablename.'('.$listfields.', import_key, entity';
							if (! empty($objimport->array_import_tables_creator[0][$alias])) $sql.=', '.$objimport->array_import_tables_creator[0][$alias];
							$sql.=') VALUES('.$listvalues.", '".$importid."', ".$conf->entity ;
						}
						if (! empty($objimport->array_import_tables_creator[0][$alias])) $sql.=', '.$user->id;
						$sql.=')';
print($sql);
						dol_syslog("import_csv.modules", LOG_DEBUG);

						//print '> '.join(',',$arrayrecord);
						//print 'sql='.$sql;
						//print '<br>'."\n";

						// Run insert request
						if ($sql)
						{
							$resql=$this->db->query($sql);
							$last_insert_id_array[$tablename] = $this->db->last_insert_id($tablename); // store the last inserted auto_increment id for each table, so that dependent tables can be inserted with the appropriate id. This must be done just after the INSERT request, else we risk losing the id (because another sql query will be issued somewhere in Dolibarr).
							if ($resql)
							{
								//print '.';
							}
							else
							{
								//print 'E';
								$this->errors[$error]['lib']=$this->db->lasterror();
								$this->errors[$error]['type']='SQL';
								$error++;
							}
						}
					}
					/*else
					{
						dol_print_error('','ErrorFieldListEmptyFor '.$alias."/".$tablename);
					}*/
				}

			    if ($error) break;
			}
		}

		return 1;
	}

}