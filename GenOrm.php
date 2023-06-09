<?php

namespace Zapovednik;
use Bitrix\Main\Application;
use Bitrix\Main\IO\FileNotFoundException;
use Bitrix\Main\Loader;

class GenOrm
{
    public string $tableName;
    public string $nameSpace;
    public string $entitiesDirPath;

    public string $langDir;

    public function __construct(string $tableName, string $nameSpace='entities', string $entitiesDirPath='', string $langDir='/lang/ru/')
    {
        if (!Application::getConnection()->isTableExists($tableName)) {
            throw new \Exception('Table not found');
        }

        $this->tableName = $tableName;
        $this->nameSpace = $nameSpace;
        $this->entitiesDirPath = $entitiesDirPath =
            !strlen($entitiesDirPath) ? $_SERVER['DOCUMENT_ROOT']."/local/php_interface/entities" : $entitiesDirPath;
        $this->langDir = $langDir;

        if (!file_exists($entitiesDirPath)) {
            var_dump($entitiesDirPath);
            throw new \Exception('Entities directory not found');
        }

        
        if (!file_exists($entitiesDirPath)) {
            throw new \Exception('Lang Entities directory not found');
        }

        Loader::includeModule('perfmon');
    }

    public function gen()
    {
        $entitiesPath    = $this->entitiesDirPath;
        $moduleNamespace = $this->nameSpace;

        $tableParts = explode("_", $this->tableName);
        array_shift($tableParts);
        if (count($tableParts) > 1)
            array_shift($tableParts);
        $className = \Bitrix\Main\Text\StringHelper::snake2camel(implode("_", $tableParts));

        $obTable = new \CPerfomanceTable;
        $obTable->Init($this->tableName);
        $arFields = $obTable->GetTableFields(false, true);

        $arUniqueIndexes = $obTable->GetUniqueIndexes();
        $hasID = false;
        foreach ($arUniqueIndexes as $indexName => $indexColumns)
        {
            if(array_values($indexColumns) === array("ID"))
                $hasID = $indexName;
        }

        if ($hasID)
        {
            $arUniqueIndexes = array($hasID => $arUniqueIndexes[$hasID]);
        }

        $obSchema = new \CPerfomanceSchema;
        $arParents = $obSchema->GetParents($this->tableName);
        $arValidators = array();
        $arMessages = array();

        $shortAliases = \Bitrix\Main\Config\Option::get('perfmon', 'tablet_short_aliases') == 'Y';
        $objectSettings = \Bitrix\Main\Config\Option::get('perfmon', 'tablet_object_settings') == 'Y';
        $useMapIndex = \Bitrix\Main\Config\Option::get('perfmon', 'tablet_use_map_index') == 'Y';

        $dateFunctions = array(
            'curdate' => true,
            'current_date' => true,
            'current_time' => true,
            'current_timestamp' => true,
            'curtime' => true,
            'localtime' => true,
            'localtimestamp' => true,
            'now' => true
        );

        $descriptions = array();
        $fields = array();
        $fieldClassPrefix = '';
        $validatorPrefix = '';
        $referencePrefix = '';
        $datetimePrefix = '';
        $aliases = array(
            'Bitrix\Main\Localization\Loc',
            'Bitrix\Main\ORM\Data\DataManager'
        );
        if (!$shortAliases)
        {
            $fieldClassPrefix = 'Fields\\';
            $validatorPrefix = $fieldClassPrefix.'Validators\\';
            $referencePrefix = $fieldClassPrefix.'Relations\\';
            $datetimePrefix = 'Type\\';
            $aliases[] = 'Bitrix\Main\ORM\Fields';
        }

        $fieldClasses = array(
            'integer' => 'IntegerField',
            'float' => 'FloatField',
            'boolean' => 'BooleanField',
            'string' => 'StringField',
            'text' => 'TextField',
            'enum' => 'EnumField',
            'date' => 'DateField',
            'datetime' => 'DatetimeField'
        );

        foreach ($arFields as $columnName => $columnInfo)
        {
            $type = $columnInfo["orm_type"];
            if ($shortAliases)
            {
                $aliases[] = 'Bitrix\Main\ORM\Fields\\'.$fieldClasses[$type];
            }

            $match = array();
            if (
                preg_match("/^(.+)_TYPE\$/", $columnName, $match)
                && $columnInfo["length"] == 4
                && isset($arFields[$match[1]])
            )
            {
                $columnInfo["nullable"] = true;
                $columnInfo["orm_type"] = "enum";
                $columnInfo["enum_values"] = array("'text'", "'html'");
                $columnInfo["length"] = "";
            }

            $columnInfo["default"] = (string)$columnInfo["default"];
            if ($columnInfo["default"] !== '')
            {
                $columnInfo["nullable"] = true;
            }

            switch ($type)
            {
                case 'integer':
                case 'float':
                    break;
                case 'boolean':
                    if ($columnInfo["default"] !== '')
                    {
                        $columnInfo["default"] = "'".$columnInfo["default"]."'";
                    }
                    $columnInfo["type"] = "bool";
                    $columnInfo["length"] = "";
                    $columnInfo["enum_values"] = array("'N'", "'Y'");
                    break;
                case 'string':
                case 'text':
                    $columnInfo["type"] = $columnInfo["orm_type"];
                    if ($columnInfo["default"] !== '')
                    {
                        $columnInfo["default"] = "'".$columnInfo["default"]."'";
                    }
                    break;
                case 'enum':
                    if ($columnInfo["default"] !== '' && !is_numeric($columnInfo["default"]))
                    {
                        $columnInfo["default"] = "'".$columnInfo["default"]."'";
                    }
                    break;
                case 'date':
                case 'datetime':
                    if ($columnInfo["default"] !== '' && !is_numeric($columnInfo["default"]))
                    {
                        $defaultValue = mb_strtolower($columnInfo["default"]);
                        if (mb_strlen($defaultValue) > 2)
                        {
                            if (substr_compare($defaultValue, '()', -2, 2, true) === 0)
                                $defaultValue = mb_substr($defaultValue, 0, -2);
                        }
                        if (isset($dateFunctions[$defaultValue]))
                        {
                            if ($type == 'date')
                            {
                                if ($shortAliases)
                                {
                                    $aliases[] = 'Bitrix\Main\Type\Date';
                                }
                                else
                                {
                                    $aliases[] = 'Bitrix\Main\Type';
                                }
                                $columnInfo["default_text"] = 'current date';
                                $columnInfo["default"] = "function()"
                                    ."{"
                                    ."\treturn new ".$datetimePrefix."Date();"
                                    ."}";
                            }
                            else
                            {
                                if ($shortAliases)
                                {
                                    $aliases[] = 'Bitrix\Main\Type\DateTime';
                                }
                                else
                                {
                                    $aliases[] = 'Bitrix\Main\Type';
                                }
                                $columnInfo["default_text"] = 'current datetime';
                                $columnInfo["default"] = "function()"
                                    ."{"
                                    ."\treturn new ".$datetimePrefix."DateTime();"
                                    ."}";
                            }
                        }
                        else
                        {
                            $columnInfo["default"] = "'".$columnInfo["default"]."'";
                        }
                    }
                    break;
            }

            $primary = false;
            foreach ($arUniqueIndexes as $arColumns)
            {
                if (in_array($columnName, $arColumns))
                {
                    $primary = true;
                    break;
                }
            }

            $messageId = mb_strtoupper(implode("_", $tableParts)."_ENTITY_".$columnName."_FIELD");
            $arMessages[$messageId] = "";

            $descriptions[$columnName] = " * &lt;li&gt; ".$columnName
                ." ".$columnInfo["type"].($columnInfo["length"] != '' ? "(".$columnInfo["length"].")": "")
                .($columnInfo["orm_type"] === "enum" || $columnInfo["orm_type"] === "boolean" ?
                    " (".implode(", ", $columnInfo["enum_values"]).")"
                    : ""
                )
                ." ".($columnInfo["nullable"] ? "optional": "mandatory")
                .($columnInfo["default"] !== ''
                    ? " default ".(isset($columnInfo["default_text"])
                        ? $columnInfo["default_text"]
                        : $columnInfo["default"]
                    )
                    : ""
                )
                ."";

            $validateFunctionName = '';
            if ($columnInfo["orm_type"] == "string" && $columnInfo["length"] > 0)
            {
                if ($shortAliases)
                {
                    $aliases[] = 'Bitrix\Main\ORM\Fields\Validators\LengthValidator';
                }
                $validateFunctionName = "validate".\Bitrix\Main\Text\StringHelper::snake2camel($columnName);
                $arValidators[$validateFunctionName] = array(
                    "length" => $columnInfo["length"],
                    "field" => $columnName,
                );
            }

            if ($objectSettings)
            {
                $offset = ($useMapIndex ? "" : "");
                $fields[$columnName] = ""
                    .($useMapIndex ? "'".$columnName."' => " : "")
                    ."(new ".$fieldClassPrefix.$fieldClasses[$type]."('".$columnName."',"
                    .($validateFunctionName !== ''
                        ? $offset."["
                        .$offset."'validation' => [_"."_CLASS_"."_, '".$validateFunctionName."']"
                        .$offset."]"
                        : $offset."[]"
                    )
                    .$offset."))->configureTitle(Loc::getMessage('".$messageId."'))"
                    .($primary ? $offset."->configurePrimary(true)" : "")
                    .($columnInfo["increment"] ? $offset."->configureAutocomplete(true)" : "")
                    .(!$primary && $columnInfo["nullable"] === false ? $offset."->configureRequired(true)" : "")
                    .($columnInfo["orm_type"] === "boolean"
                        ? $offset."->configureValues(".implode(", ", $columnInfo["enum_values"]).")"
                        : ""
                    )
                    .($columnInfo["orm_type"] === "enum"
                        ? $offset."->configureValues([".implode(", ", $columnInfo["enum_values"])."])"
                        : ""
                    )
                    .($columnInfo["default"] !== '' ? $offset."->configureDefaultValue(".$columnInfo["default"].")" : "");
                $fields[$columnName] = mb_substr($fields[$columnName], 0, -1).",";
            }
            else
            {
                $fields[$columnName] = ""
                    .($useMapIndex ? "'".$columnName."' => " : "")
                    ."new ".$fieldClassPrefix.$fieldClasses[$type]."("
                    ."'".$columnName."',"
                    ."["
                    .($primary ? "'primary' => true," : "")
                    .($columnInfo["increment"] ? "'autocomplete' => true," : "")
                    .(!$primary && $columnInfo["nullable"] === false ? "'required' => true," : "")
                    .($columnInfo["orm_type"] === "boolean" || $columnInfo["orm_type"] === "enum"
                        ? "'values' => array(".implode(", ", $columnInfo["enum_values"])."),"
                        : ""
                    )
                    .($columnInfo["default"] !== '' ? "'default' => ".$columnInfo["default"]."," : "")
                    .($validateFunctionName !== '' ? "'validation' => [_"."_CLASS_"."_, '".$validateFunctionName."']," : "")
                    ."'title' => Loc::getMessage('".$messageId."')"
                    ."]"
                    ."),";
            }
        }
        foreach ($arParents as $columnName => $parentInfo)
        {
            if ($shortAliases)
            {
                $aliases[] = 'Bitrix\Main\ORM\Fields\Relations\Reference';
            }

            $parentTableParts = explode("_", $parentInfo["PARENT_TABLE"]);
            array_shift($parentTableParts);
            $parentModuleNamespace = ucfirst($parentTableParts[0]);
            $parentClassName = \Bitrix\Main\Text\StringHelper::snake2camel(implode("_", $parentTableParts));

            $columnNameEx = preg_replace("/_ID\$/", "", $columnName);
            if (isset($descriptions[$columnNameEx]))
            {
                $columnNameEx = mb_strtoupper($parentClassName);
            }
            $descriptions[$columnNameEx] = " * &lt;li&gt; ".$columnName
                ." reference to {@link \\Bitrix\\".$parentModuleNamespace
                ."\\".$parentClassName."Table}"
                ."";

            $fields[$columnNameEx] = ""
                .($useMapIndex ? "'".$columnNameEx."' => " : "")
                ."new ".$referencePrefix."Reference("
                ."'".$columnNameEx."',"
                ."'\Bitrix\\".$parentModuleNamespace."\\".$parentClassName."',"
                ."['=this.".$columnName."' => 'ref.".$parentInfo["PARENT_COLUMN"]."'],"
                ."['join_type' => 'LEFT']"
                ."),";
        }

        $aliases = array_unique($aliases);
        sort($aliases);

        $entityPath  = $entitiesPath.'/'.$className."Table.php";
        $entityFileContent = '';

        $entityFileContent .= "<"."?"."php";
        $entityFileContent .= " namespace ".$moduleNamespace.";";
        $entityFileContent .= "";
        $entityFileContent .= "use ".implode(",", $aliases).";";
        $entityFileContent .= "";
        $entityFileContent .= "Loc::loadMessages(_"."_FILE_"."_);";
        $entityFileContent .= "";
        $entityFileContent .= "/"."**";
        $entityFileContent .= " * Class ".$className."Table";
        $entityFileContent .= " * ";
        $entityFileContent .= " * Fields:";
        $entityFileContent .= " * &lt;ul&gt;";
        $entityFileContent .= implode('', $descriptions);
        $entityFileContent .= " * &lt;/ul&gt;";
        $entityFileContent .= " *";
        $entityFileContent .= " * @package Bitrix\\".$moduleNamespace."";
        $entityFileContent .= " *"."*/";
        $entityFileContent .= "";
        $entityFileContent .= "class ".$className."Table extends DataManager";
        $entityFileContent .= "{";
        $entityFileContent .= "/**";
        $entityFileContent .= " * Returns DB table name for entity.";
        $entityFileContent .= " *";
        $entityFileContent .= " * @return string";
        $entityFileContent .= " */";
        $entityFileContent .= "\tpublic static function getTableName()";
        $entityFileContent .= "{";
        $entityFileContent .= "\treturn '".$this->tableName."';";
        $entityFileContent .= "}";
        $entityFileContent .= "";
        $entityFileContent .= "/**";
        $entityFileContent .= " * Returns entity map definition.";
        $entityFileContent .= " *";
        $entityFileContent .= " * @return array";
        $entityFileContent .= " */";
        $entityFileContent .= "\tpublic static function getMap()";
        $entityFileContent .= "{";
        $entityFileContent .= "\treturn [";
        $entityFileContent .= implode('', $fields);
        $entityFileContent .= "];";
        $entityFileContent .= "}";
        foreach ($arValidators as $validateFunctionName => $validator)
        {
            $entityFileContent .= "/**";
            $entityFileContent .= " * Returns validators for ".$validator["field"]." field.";
            $entityFileContent .= " *";
            $entityFileContent .= " * @return array";
            $entityFileContent .= " */";
            $entityFileContent .= "\tpublic static function ".$validateFunctionName."()";
            $entityFileContent .= "{";
            $entityFileContent .= "\treturn [";
            $entityFileContent .= "\tnew ".$validatorPrefix."LengthValidator(null, ".$validator["length"]."),";
            $entityFileContent .= "];";
            $entityFileContent .= "}";
        }
        $entityFileContent .= "}";

        $langPath = $entitiesPath.$this->langDir.mb_strtolower($className)."table.php";

        $entityLangFileContent = '';

        $entityLangFileContent .= "<?";
        foreach ($arMessages as $messageId => $messageText)
        {
            $entityLangFileContent .= "\$MESS[\"".$messageId."\"] = \"".EscapePHPString($messageText)."\";";
        }
        $entityLangFileContent .= "?>";

        file_put_contents($entityPath, htmlspecialchars_decode($entityFileContent));
        file_put_contents($langPath, htmlspecialchars_decode($entityLangFileContent));
    }
}