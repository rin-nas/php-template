<?php
/**
 * PHP based template class
 *
 * Purpose
 *   * The class can be used in the component approach (MVC),
 *     when the template — a leading, component (controller) — slave.
 *   * In view of the high speed of PHP-template perhaps
 *     the best solution for high loaded projects.
 *
 * Features and advantages
 *   * High speed execution, flexibility and power (by PHP)
 *   * Very easy to use
 *   * The local name space within a single template
 *   * Ability to secure execution of PHP code (checking for valid syntax)
 *
 * Disadvantages
 *   * A bit verbose syntax (by PHP) :)
 *
 * Usage in templates
 *   You may use any control structures, available in PHP (if/else, foreach, do/while, break/continue, etc.)
 *   Basic print: <?=$var?>, <?=$array['key']?>
 *   Print with helpers: <?=UTF8::strlen($var)?>
 *   To insert another template with the same name space, use
 *       <? require(__DIR__ . '/filename.ext') ?> (If the file is not found, the script throws E_ERROR and exit)
 *       <? include(__DIR__ . '/filename.ext') ?> (If the file is not found, the script throws E_WARNING and continue work)
 *   To insert another template with his isolated name space, use
 *       <?=Template::render(__DIR__ . '/filename.ext', $vars)?> or
 *       <?=Template::execute($template_content, $vars)?>
 *
 * Hints
 *   To get all local variables in a template you can use get_defined_vars().
 *   Short entries:
 *       <?=@$v?>        instead of <?=isset($v) ? $v : ''?>
 *       <? if (@$v): ?> instead of <? if (! empty($v)): ?>
 *
 * Limitations
 *   * Syntax <script language="php">…</script> is not supported (for security reasons).
 *   * $this object from the template is not available (for security reasons).
 *     Another objects can be passed to the template without limitations.
 *   * By default, helpers can be only PHP's built-in functions or the static methods of classes.
 *     You can also use the methods of objects accessible from the template.
 *
 * History
 *   This class is being developed since 2005 and has proved to be excellent.
 *   Experience has shown that template engines such as Smarty, Twig and other
 *   causes more problems than benefits.
 *   The main disadvantages: new syntax, limited functionality,
 *   execution low speed and/or compilation overhead, problems with debugging.
 *   PHP based template class does not have these disadvantages.
 *
 * Useful links
 *   http://www.phpwact.org/pattern/model_view_controller  About MVC
 *
 * @link     http://code.google.com/p/php-template/
 * @license  http://creativecommons.org/licenses/by-sa/3.0/
 * @author   Nasibullin Rinat
 * @version  2.2.0
 */
class Template
{
	#constants that are used in self::execute()
	const EXECUTE_MODE_NO_CHECK   = 0;  #do not check for a valid PHP syntax inserts
	const EXECUTE_MODE_REMOVE     = 1;  #check for a valid PHP syntax inserts, in the case of mismatch delete
	const EXECUTE_MODE_PRESERVE   = 2;  #check for a valid PHP syntax inserts, in the case of mismatch leave as is (the code will not executed)
	const EXECUTE_MODE_HTML_QUOTE = 4;  #check for a valid PHP syntax inserts, in the case of mismatch leave as is with quoting by htmlspecialchars()

	/**
	 * PHP syntax with limitations (for the safe execution of code)
	 *
	 * Supports reference to the variables that start with $ (dollar),
	 * including object properties, array elements, string, int, float, bool, null.
	 *
	 * @var string
	 */
	const PHP_EXPRESSION_RE = '
			(?> @?+                             #ignore E_NOTICE and E_USER_NOTICE if necessary
				\$ [a-zA-Z_][a-zA-Z_\d]*+       #variable

				(?> \[
						(?>  \'  (?>[^\'\\\\]+|\\\\.)*+  \'  #string as array key
						  |  "   (?>[^"\\\\]+ |\\\\.)*+  "   #string as array key
						  |  \d++                            #digits as array key
						)
					\]                          #variable as array element
				  | -> [a-zA-Z_][a-zA-Z_\d]*+   #variable as object property
				)*+

				| \'  (?>[^\'\\\\]+|\\\\.)*+  \'  #string
				| "   (?>[^"\\\\]+ |\\\\.)*+  "   #string
				| -?+ \d++ (?>\.\d++)?+           #numbers (unsigned/signed int/float)
				| true|false                      #bool
				| null                            #null
			)';

	/**
	 * template variables
	 *
	 * @var array
	 */
	private $_vars = array();

	/**
	 * @var string
	 */
	private $_filename = null;

	/**
	 * Constructor
	 *
	 * @param  string|null  $filename  The file name you want to load
	 */
	public function __construct($filename = null)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		$this->_filename = $filename;
	}

	/**
	 * Set the start of capture of the template
	 * Hint:
	 *		It is possible within a single template to keep a few sub-templates, code example:
	 *		<? Template::begin() ?>
	 *          <ul>
	 *			<%foreach ($rows as $row) : %>
	 *				<li><%=$row['caption']%></li>
	 *			<%endforeach%>
	 *          </ul>
	 *		<? $tpl = Template::end() ?>
	 *		...
	 *		<?= Template::execute($tpl, array('rows' => $rows)) ?>
	 *
	 * @return bool
	 */
	public static function begin()
	{
		return ob_start();
	}

	/**
	 * Set the end of the capture of the template and return its contents
	 *
	 * @return  string|bool|null  If output buffering isn't active then FALSE is returned.
	 */
	public static function end(/*callback $filter1, callback $filter2, ...*/)
	{
		$s = ob_get_clean();
		if (! is_string($s)) return false;

		foreach (func_get_args() as $arg)
		{
			if (is_callable($arg)) $s = call_user_func($arg, $s);
			if (! is_string($s)) return $s === null ? null : false;
		}

		//if ($is_strip_spaces && is_string($s)) $s = trim(preg_replace('/[\x00-\x20\x7f]++/sSX', ' ', $s), ' ');
		return $s;
	}

	/**
	 * Set a template variable(s)
	 *
	 * @param   string|array              $name   Variable name or array of variables
	 * @param   scalar|array|object|null  $value  Variable value, any type except "resource"
	 * @return  bool                              TRUE if ok, FALSE + E_USER_WARNING if error occurred
	 */
	public function assign($name, $value = null)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_array($name))
		{
			#checking first level of array
			foreach ($name as $k => $v)
			{
				if (! is_string($k))
				{
					trigger_error('A string type key expected in array on first level, ' . gettype($k) . ' given!', E_USER_WARNING);
					return false;
				}
				if (ctype_digit($k))
				{
					trigger_error('Unexpected a digit type key in array on first level!', E_USER_WARNING);
					return false;
				}
				if (is_resource($v))
				{
					trigger_error('Unexpected resource type in element of array with "' . $k . '" key', E_USER_WARNING);
					return false;
				}
				else $this->_vars[$k] = $v;
			}
			return true;
		}
		if (ctype_digit($name))
		{
			trigger_error('Unexpected a digit type value in 1-st parameter!', E_USER_WARNING);
			return false;
		}
		$this->_vars[$name] = $value;
		return true;
	}

	/**
	 * Open, parse, and return the template file.
	 * Method can be called as static!
	 * If file of the template is not found, gives warning and returns FALSE
	 *
	 * @param   string|null        $filename  Template file name
	 * @param   array|null         $vars      Template variables
	 * @return  string|array|bool             Returns FALSE if error occurred
	 */
	public /*static*/ function render($filename = null, array $vars = null)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if ($filename === null && isset($this)) $filename = $this->_filename;
		if (! is_string($filename))
		{
			trigger_error('Unknown filename!', E_USER_WARNING);
			return false;
		}
		if (! file_exists($filename))
		{
			trigger_error('File "' . $filename . '" does not exist!', E_USER_WARNING);
			return false;
		}
		if ($vars === null && isset($this)) $vars = $this->_vars;
		return self::_sandbox($filename, $vars);
	}

	/**
	 * Treats the incoming string as template with the PHP code inserts, executes code and returns the result.
	 * If necessary, checks the syntax of PHP code to the limitations, see self:: PHP_EXPRESSION_RE
	 *
	 * @param   string|null  $s                Template content
	 * @param   array|null   $vars             Template variables
	 * @param   int          $mode             Mode, see self::EXECUTE_MODE_*
	 * @param   string       $allow_funcs_re   Regexp for possible helpers
	 * @param   bool         $_is_check_syntax Used by self::valid()
	 * @return  string|bool                    Returns FALSE if error occurred
	 */
	public static function execute(
		$s,
		array $vars = null,
		$mode = self::EXECUTE_MODE_PRESERVE,
		$allow_funcs_re = '(?:htmlspecialchars|rawurlencode)',
		$_is_check_syntax = false)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (! is_string($s) || (strpos($s, '<?') === false && strpos($s, '<%') === false)) return $s;

		if ($mode === self::EXECUTE_MODE_NO_CHECK) $s2 = preg_replace('~<%(.*?)%>~sSX', '<?$1?>', $s);
		else
		{
			#check PHP code for valid syntax with limitations
			$class = __CLASS__;
			$s2 = preg_replace_callback(
				'~<([\?%]) .*? \\1>~sxSX',
				function (array $m) use ($mode, $allow_funcs_re, $class)
				{
					$s = substr($m[0], 2, -2);
					if (preg_match('~^= (?<main>
											[\x00-\x20]*+

											(?: ' . $class::PHP_EXPRESSION_RE . '
											  | ' . $allow_funcs_re . ' \( (?&main)
																	 (?:, (?&main) )*+
																  \)
											)++

											[\x00-\x20]*+
										)
									$~sxSX', $s)) return '<?' . $s . '?>';
					#if the code does not match the syntax with limitations
					if ($mode === $class::EXECUTE_MODE_REMOVE)     return '';
					if ($mode === $class::EXECUTE_MODE_PRESERVE)   return '< ?' . $s . '? >';
					if ($mode === $class::EXECUTE_MODE_HTML_QUOTE) return htmlspecialchars('<?' . $s . '?>');
					trigger_error('Unknown mode', E_USER_ERROR);
				},
				$s);
		}
		if ($_is_check_syntax)
		{
			if ($s2 !== $s) return false;
			return @self::_sandbox('<? return TRUE ?>' . $s2, null, false);
		}
		$s = self::_sandbox($s2, $vars, false);
		if (is_string($s) && $mode === self::EXECUTE_MODE_PRESERVE) $s = preg_replace('~<\x20\? (.*?) \?\x20>~sxSX', '<?$1?>', $s);
		return $s;
	}

	/**
	 * Syntax check
	 *
	 * @param   string|null  $s               Template content
	 * @param   int          $mode            Mode, see self::EXECUTE_MODE_*
	 * @param   string|null  $allow_funcs_re  Regexp for possible helpers
	 * @return  bool                          TRUE if ok, FALSE otherwise
	 */
	public static function valid(
		$s,
		$mode = self::EXECUTE_MODE_REMOVE,
		$allow_funcs_re = '(?:htmlspecialchars|rawurlencode)')
	{
		return self::execute($s, null, $mode, $allow_funcs_re, true);
	}

	/**
	 * "Sandbox" for the execution of PHP code
	 * 1. PHP code is executed strictly in a static method,
	 *    so that the template did not have access to the object $this!
	 * 2. Names space (variables scope) is limited only by this method!
	 *
	 * @param   string             $__s           Template filename or content
	 * @param   array|null         $__vars        Template variables
	 * @param   bool               $__is_include  Include filename or evaluate content
	 * @return  string|array|bool  Returns FALSE if error occurred
	 */
	private static function _sandbox($__s, array $__vars = null, $__is_include = true)
	{
		if ($__vars) extract($__vars, EXTR_SKIP);   #extract the variables to local name space! (if there is a collision, don't overwrite the existing variable for security purpose)
		ob_start();                                 #start output buffering
		if ($__is_include) $state = include($__s);  #include the template PHP file
		else $state = eval('?>' . $__s);            #or evaluate a string as PHP code
		$s = ob_get_clean();                        #get current output buffer contents and delete it
		return (is_bool($state) || is_array($state)) ? $state : $s;
	}

}