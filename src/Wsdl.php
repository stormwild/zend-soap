<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @package   Zend_Soap
 */

namespace Zend\Soap;

use DOMDocument;
use DOMElement;
use Zend\Soap\Wsdl\ComplexTypeStrategy\ComplexTypeStrategyInterface as ComplexTypeStrategy;
use Zend\Uri\Uri;

/**
 * \Zend\Soap\Wsdl
 *
 * @category   Zend
 * @package    Zend_Soap
 */
class Wsdl
{
    /**
     * @var object DomDocument Instance
     */
    private $_dom;

    /**
     * @var object WSDL Root XML_Tree_Node
     */
    private $_wsdl;

    /**
     * @var string URI where the WSDL will be available
     */
    private $_uri;

    /**
     * @var DOMElement
     */
    private $_schema = null;

    /**
     * Types defined on schema
     *
     * @var array
     */
    private $_includedTypes = array();

    /**
     * Strategy for detection of complex types
     */
    protected $_strategy = null;

    /**
     * Map of PHP Class names to WSDL QNames.
     *
     * @var array
     */
    protected $_classMap = array();

    /**
     * Constructor
     *
     * @param string  $name Name of the Web Service being Described
     * @param string|Uri $uri URI where the WSDL will be available
     * @param ComplexTypeStrategy $strategy
     */
    public function __construct($name, $uri, ComplexTypeStrategy $strategy = null, array $classMap = array())
    {
        if ($uri instanceof Uri) {
            $uri = $uri->toString();
        }
        $this->_uri = $uri;
        $this->_classMap = $classMap;

        /**
         * @todo change DomDocument object creation from cparsing to constructing using API
         * It also should authomatically escape $name and $uri values if necessary
         */
        $wsdl = "<?xml version='1.0' ?>
                <definitions name='$name' targetNamespace='$uri'
                    xmlns='http://schemas.xmlsoap.org/wsdl/'
                    xmlns:tns='$uri'
                    xmlns:soap='http://schemas.xmlsoap.org/wsdl/soap/'
                    xmlns:xsd='http://www.w3.org/2001/XMLSchema'
                    xmlns:soap-enc='http://schemas.xmlsoap.org/soap/encoding/'
                    xmlns:wsdl='http://schemas.xmlsoap.org/wsdl/'></definitions>";
        $this->_dom = new DOMDocument();
        if (!$this->_dom->loadXML($wsdl)) {
            throw new Exception\RuntimeException('Unable to create DomDocument');
        } else {
            $this->_wsdl = $this->_dom->documentElement;
        }

        $this->setComplexTypeStrategy($strategy ?: new Wsdl\ComplexTypeStrategy\DefaultComplexType);
    }

    /**
     * Get the class map of php to wsdl qname types.
     *
     * @return array
     */
    public function getClassMap()
    {
        return $this->_classMap;
    }

    /**
     * Set the class map of php to wsdl qname types.
     */
    public function setClassMap($classMap)
    {
        $this->_classMap = $classMap;
    }

    /**
     * Set a new uri for this WSDL
     *
     * @param  string|Uri $uri
     * @return \Zend\Server\Wsdl
     */
    public function setUri($uri)
    {
        if ($uri instanceof Uri) {
            $uri = $uri->toString();
        }
        $oldUri = $this->_uri;
        $this->_uri = $uri;

        if($this->_dom !== null) {
            // @todo: This is the worst hack ever, but its needed due to design and non BC issues of WSDL generation
            $xml = $this->_dom->saveXML();
            $xml = str_replace($oldUri, $uri, $xml);
            $this->_dom = new DOMDocument();
            $this->_dom->loadXML($xml);
        }

        return $this;
    }

    /**
     * Set a strategy for complex type detection and handling
     *
     * @param ComplexTypeStrategy $strategy
     * @return \Zend\Soap\Wsdl
     */
    public function setComplexTypeStrategy(ComplexTypeStrategy $strategy)
    {
        $this->_strategy = $strategy;
        return $this;
    }

    /**
     * Get the current complex type strategy
     *
     * @return ComplexTypeStrategy
     */
    public function getComplexTypeStrategy()
    {
        return $this->_strategy;
    }

    /**
     * Add a {@link http://www.w3.org/TR/wsdl#_messages message} element to the WSDL
     *
     * @param string $name Name for the {@link http://www.w3.org/TR/wsdl#_messages message}
     * @param array $parts An array of {@link http://www.w3.org/TR/wsdl#_message parts}
     *                     The array is constructed like: 'name of part' => 'part xml schema data type'
     *                     or 'name of part' => array('type' => 'part xml schema type')
     *                     or 'name of part' => array('element' => 'part xml element name')
     * @return object The new message's XML_Tree_Node for use in {@link function addDocumentation}
     */
    public function addMessage($name, $parts)
    {
        $message = $this->_dom->createElement('message');

        $message->setAttribute('name', $name);

        if (count($parts) > 0) {
            foreach ($parts as $name => $type) {
                $part = $this->_dom->createElement('part');
                $part->setAttribute('name', $name);
                if (is_array($type)) {
                    foreach ($type as $key => $value) {
                        $part->setAttribute($key, $value);
                    }
                } else {
                    $part->setAttribute('type', $type);
                }
                $message->appendChild($part);
            }
        }

        $this->_wsdl->appendChild($message);

        return $message;
    }

    /**
     * Add a {@link http://www.w3.org/TR/wsdl#_porttypes portType} element to the WSDL
     *
     * @param string $name portType element's name
     * @return object The new portType's XML_Tree_Node for use in {@link function addPortOperation} and {@link function addDocumentation}
     */
    public function addPortType($name)
    {
        $portType = $this->_dom->createElement('portType');
        $portType->setAttribute('name', $name);
        $this->_wsdl->appendChild($portType);

        return $portType;
    }

    /**
     * Add an {@link http://www.w3.org/TR/wsdl#request-response operation} element to a portType element
     *
     * @param object $portType a portType XML_Tree_Node, from {@link function addPortType}
     * @param string $name Operation name
     * @param string $input Input Message
     * @param string $output Output Message
     * @param string $fault Fault Message
     * @return object The new operation's XML_Tree_Node for use in {@link function addDocumentation}
     */
    public function addPortOperation($portType, $name, $input = false, $output = false, $fault = false)
    {
        $operation = $this->_dom->createElement('operation');
        $operation->setAttribute('name', $name);

        if (is_string($input) && (strlen(trim($input)) >= 1)) {
            $node = $this->_dom->createElement('input');
            $node->setAttribute('message', $input);
            $operation->appendChild($node);
        }
        if (is_string($output) && (strlen(trim($output)) >= 1)) {
            $node= $this->_dom->createElement('output');
            $node->setAttribute('message', $output);
            $operation->appendChild($node);
        }
        if (is_string($fault) && (strlen(trim($fault)) >= 1)) {
            $node = $this->_dom->createElement('fault');
            $node->setAttribute('message', $fault);
            $operation->appendChild($node);
        }

        $portType->appendChild($operation);

        return $operation;
    }

    /**
     * Add a {@link http://www.w3.org/TR/wsdl#_bindings binding} element to WSDL
     *
     * @param string $name Name of the Binding
     * @param string $type name of the portType to bind
     * @return object The new binding's XML_Tree_Node for use with {@link function addBindingOperation} and {@link function addDocumentation}
     */
    public function addBinding($name, $portType)
    {
        $binding = $this->_dom->createElement('binding');
        $binding->setAttribute('name', $name);
        $binding->setAttribute('type', $portType);

        $this->_wsdl->appendChild($binding);

        return $binding;
    }

    /**
     * Add an operation to a binding element
     *
     * @param object $binding A binding XML_Tree_Node returned by {@link function addBinding}
     * @param array $input An array of attributes for the input element, allowed keys are: 'use', 'namespace', 'encodingStyle'. {@link http://www.w3.org/TR/wsdl#_soap:body More Information}
     * @param array $output An array of attributes for the output element, allowed keys are: 'use', 'namespace', 'encodingStyle'. {@link http://www.w3.org/TR/wsdl#_soap:body More Information}
     * @param array $fault An array of attributes for the fault element, allowed keys are: 'name', 'use', 'namespace', 'encodingStyle'. {@link http://www.w3.org/TR/wsdl#_soap:body More Information}
     * @return object The new Operation's XML_Tree_Node for use with {@link function addSoapOperation} and {@link function addDocumentation}
     */
    public function addBindingOperation($binding, $name, $input = false, $output = false, $fault = false)
    {
        $operation = $this->_dom->createElement('operation');
        $operation->setAttribute('name', $name);

        if (is_array($input)) {
            $node = $this->_dom->createElement('input');
            $soap_node = $this->_dom->createElement('soap:body');
            foreach ($input as $name => $value) {
                $soap_node->setAttribute($name, $value);
            }
            $node->appendChild($soap_node);
            $operation->appendChild($node);
        }

        if (is_array($output)) {
            $node = $this->_dom->createElement('output');
            $soap_node = $this->_dom->createElement('soap:body');
            foreach ($output as $name => $value) {
                $soap_node->setAttribute($name, $value);
            }
            $node->appendChild($soap_node);
            $operation->appendChild($node);
        }

        if (is_array($fault)) {
            $node = $this->_dom->createElement('fault');
            if (isset($fault['name'])) {
                $node->setAttribute('name', $fault['name']);
            }
            $soap_node = $this->_dom->createElement('soap:fault');
            foreach ($fault as $name => $value) {
                $soap_node->setAttribute($name, $value);
            }
            $node->appendChild($soap_node);
            $operation->appendChild($node);
        }

        $binding->appendChild($operation);

        return $operation;
    }

    /**
     * Add a {@link http://www.w3.org/TR/wsdl#_soap:binding SOAP binding} element to a Binding element
     *
     * @param object $binding A binding XML_Tree_Node returned by {@link function addBinding}
     * @param string $style binding style, possible values are "rpc" (the default) and "document"
     * @param string $transport Transport method (defaults to HTTP)
     * @return boolean
     */
    public function addSoapBinding($binding, $style = 'document', $transport = 'http://schemas.xmlsoap.org/soap/http')
    {
        $soap_binding = $this->_dom->createElement('soap:binding');
        $soap_binding->setAttribute('style', $style);
        $soap_binding->setAttribute('transport', $transport);

        $binding->appendChild($soap_binding);

        return $soap_binding;
    }

    /**
     * Add a {@link http://www.w3.org/TR/wsdl#_soap:operation SOAP operation} to an operation element
     *
     * @param object $operation An operation XML_Tree_Node returned by {@link function addBindingOperation}
     * @param string $soap_action SOAP Action
     * @return boolean
     */
    public function addSoapOperation($binding, $soap_action)
    {
        if ($soap_action instanceof Uri) {
            $soap_action = $soap_action->toString();
        }
        $soap_operation = $this->_dom->createElement('soap:operation');
        $soap_operation->setAttribute('soapAction', $soap_action);

        $binding->insertBefore($soap_operation, $binding->firstChild);

        return $soap_operation;
    }

    /**
     * Add a {@link http://www.w3.org/TR/wsdl#_services service} element to the WSDL
     *
     * @param string $name Service Name
     * @param string $port_name Name of the port for the service
     * @param string $binding Binding for the port
     * @param string $location SOAP Address for the service
     * @return object The new service's XML_Tree_Node for use with {@link function addDocumentation}
     */
    public function addService($name, $port_name, $binding, $location)
    {
        if ($location instanceof Uri) {
            $location = $location->toString();
        }
        $service = $this->_dom->createElement('service');
        $service->setAttribute('name', $name);

        $port = $this->_dom->createElement('port');
        $port->setAttribute('name', $port_name);
        $port->setAttribute('binding', $binding);

        $soap_address = $this->_dom->createElement('soap:address');
        $soap_address->setAttribute('location', $location);

        $port->appendChild($soap_address);
        $service->appendChild($port);

        $this->_wsdl->appendChild($service);

        return $service;
    }

    /**
     * Add a documentation element to any element in the WSDL.
     *
     * Note that the WSDL {@link http://www.w3.org/TR/wsdl#_documentation specification} uses 'document',
     * but the WSDL {@link http://schemas.xmlsoap.org/wsdl/ schema} uses 'documentation' instead.
     * The {@link http://www.ws-i.org/Profiles/BasicProfile-1.1-2004-08-24.html#WSDL_documentation_Element WS-I Basic Profile 1.1} recommends using 'documentation'.
     *
     * @param object $input_node An XML_Tree_Node returned by another method to add the documentation to
     * @param string $documentation Human readable documentation for the node
     * @return DOMElement The documentation element
     */
    public function addDocumentation($input_node, $documentation)
    {
        if ($input_node === $this) {
            $node = $this->_dom->documentElement;
        } else {
            $node = $input_node;
        }

        $doc = $this->_dom->createElement('documentation');
        $doc_cdata = $this->_dom->createTextNode(str_replace(array("\r\n", "\r"), "\n", $documentation));
        $doc->appendChild($doc_cdata);

        if($node->hasChildNodes()) {
            $node->insertBefore($doc, $node->firstChild);
        } else {
            $node->appendChild($doc);
        }

        return $doc;
    }

    /**
     * Add WSDL Types element
     *
     * @param object $types A DomDocument|DomNode|DomElement|DomDocumentFragment with all the XML Schema types defined in it
     */
    public function addTypes($types)
    {
        if ($types instanceof \DomDocument) {
            $dom = $this->_dom->importNode($types->documentElement);
            $this->_wsdl->appendChild($types->documentElement);
        } elseif ($types instanceof \DomNode || $types instanceof \DomElement || $types instanceof \DomDocumentFragment ) {
            $dom = $this->_dom->importNode($types);
            $this->_wsdl->appendChild($dom);
        }
    }

    /**
     * Add a complex type name that is part of this WSDL and can be used in signatures.
     *
     * @param string $type
     * @param string $wsdlType
     * @return \Zend\Soap\Wsdl
     */
    public function addType($type, $wsdlType)
    {
        if(!isset($this->_includedTypes[$type])) {
            $this->_includedTypes[$type] = $wsdlType;
        }
        return $this;
    }

    /**
     * Return an array of all currently included complex types
     *
     * @return array
     */
    public function getTypes()
    {
        return $this->_includedTypes;
    }

    /**
     * Return the Schema node of the WSDL
     *
     * @return DOMElement
     */
    public function getSchema()
    {
        if($this->_schema == null) {
            $this->addSchemaTypeSection();
        }

        return $this->_schema;
    }

    /**
     * Return the WSDL as XML
     *
     * @return string WSDL as XML
     */
    public function toXML()
    {
           return $this->_dom->saveXML();
    }

    /**
     * Return DOM Document
     *
     * @return object DomDocum ent
     */
    public function toDomDocument()
    {
        return $this->_dom;
    }

    /**
     * Echo the WSDL as XML
     *
     * @return boolean
     */
    public function dump($filename = false)
    {
        if (!$filename) {
            echo $this->toXML();
            return true;
        } else {
            return file_put_contents($filename, $this->toXML());
        }
    }

    /**
     * Returns an XSD Type for the given PHP type
     *
     * @param string $type PHP Type to get the XSD type for
     * @return string
     */
    public function getType($type)
    {
        switch (strtolower($type)) {
            case 'string':
            case 'str':
                return 'xsd:string';
            case 'long':
                return 'xsd:long';
            case 'int':
            case 'integer':
                return 'xsd:int';
            case 'float':
                return 'xsd:float';
            case 'double':
                return 'xsd:double';
            case 'boolean':
            case 'bool':
                return 'xsd:boolean';
            case 'array':
                return 'soap-enc:Array';
            case 'object':
                return 'xsd:struct';
            case 'mixed':
                return 'xsd:anyType';
            case 'void':
                return '';
            default:
                // delegate retrieval of complex type to current strategy
                return $this->addComplexType($type);
            }
    }

    /**
     * This function makes sure a complex types section and schema additions are set.
     *
     * @return \Zend\Soap\Wsdl
     */
    public function addSchemaTypeSection()
    {
        if ($this->_schema === null) {
            $this->_schema = $this->_dom->createElement('xsd:schema');
            $this->_schema->setAttribute('targetNamespace', $this->_uri);
            $types = $this->_dom->createElement('types');
            $types->appendChild($this->_schema);
            $this->_wsdl->appendChild($types);
        }
        return $this;
    }

    /**
     * Translate PHP type into WSDL QName
     *
     * @param string $type
     * @return string QName
     */
    public function translateType($type)
    {
        if (isset($this->_classMap[$type])) {
            return $this->_classMap[$type];
        }

        if ($type[0] == '\\') {
            $type = substr($type, 1);
        }

        $pos = strrpos($type, '\\');
        if ($pos) {
            $type = substr($type, $pos+1);
        }

        return str_replace('\\', '.', $type);
    }

    /**
     * Add a {@link http://www.w3.org/TR/wsdl#_types types} data type definition
     *
     * @param string $type Name of the class to be specified
     * @return string XSD Type for the given PHP type
     */
    public function addComplexType($type)
    {
        if (isset($this->_includedTypes[$type])) {
            return $this->_includedTypes[$type];
        }
        $this->addSchemaTypeSection();

        $strategy = $this->getComplexTypeStrategy();
        $strategy->setContext($this);
        // delegates the detection of a complex type to the current strategy
        return $strategy->addComplexType($type);
    }

    /**
     * Parse an xsd:element represented as an array into a DOMElement.
     *
     * @param array $element an xsd:element represented as an array
     * @return DOMElement parsed element
     */
    private function _parseElement($element)
    {
        if (!is_array($element)) {
            throw new Exception\RuntimeException("The 'element' parameter needs to be an associative array.");
        }

        $elementXml = $this->_dom->createElement('xsd:element');
        foreach ($element as $key => $value) {
            if (in_array($key, array('sequence', 'all', 'choice'))) {
                if (is_array($value)) {
                    $complexType = $this->_dom->createElement('xsd:complexType');
                    if (count($value) > 0) {
                        $container = $this->_dom->createElement('xsd:' . $key);
                        foreach ($value as $subelement) {
                            $subelementXml = $this->_parseElement($subelement);
                            $container->appendChild($subelementXml);
                        }
                        $complexType->appendChild($container);
                    }
                    $elementXml->appendChild($complexType);
                }
            } else {
                $elementXml->setAttribute($key, $value);
            }
        }
        return $elementXml;
    }

    /**
     * Add an xsd:element represented as an array to the schema.
     *
     * Array keys represent attribute names and values their respective value.
     * The 'sequence', 'all' and 'choice' keys must have an array of elements as their value,
     * to add them to a nested complexType.
     *
     * Example: array( 'name' => 'MyElement',
     *                 'sequence' => array( array('name' => 'myString', 'type' => 'string'),
     *                                      array('name' => 'myInteger', 'type' => 'int') ) );
     * Resulting XML: <xsd:element name="MyElement"><xsd:complexType><xsd:sequence>
     *                  <xsd:element name="myString" type="string"/>
     *                  <xsd:element name="myInteger" type="int"/>
     *                </xsd:sequence></xsd:complexType></xsd:element>
     *
     * @param array $element an xsd:element represented as an array
     * @return string xsd:element for the given element array
     */
    public function addElement($element)
    {
        $schema = $this->getSchema();
        $elementXml = $this->_parseElement($element);
        $schema->appendChild($elementXml);
        return 'tns:' . $element['name'];
    }
}
