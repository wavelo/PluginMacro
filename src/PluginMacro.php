<?php

use Latte\Compiler;
use Latte\Macros\MacroSet;
use Latte\MacroTokens;
use Latte\MacroNode;
use Latte\PhpWriter;


/**
 * - n:plugin
 * - n:skip
 */
class PluginMacro extends MacroSet
{

	/** @var bool|NULL */
	public static $skip = NULL;

	/** @var string[] */
	public static $masks = [];


	public static function install(Compiler $compiler)
	{
		$me = new self($compiler);

		$me->addMacro('plugin', NULL, [$me, 'macroPluginEnd']);
		$me->addMacro('skip', [$me, 'macroSkip'], [$me, 'macroSkipEnd']);
	}


	public static function installExtended(Compiler $compiler)
	{
		$me = new self($compiler);

		$me->addMacro('target', NULL, [$me, 'macroPluginEnd']);
		$me->addMacro('static', NULL, [$me, 'macroLabelEnd']);
		$me->addMacro('atomic', NULL, [$me, 'macroLabelEnd']);
		$me->addMacro('layer', NULL, [$me, 'macroLayerEnd']);
	}


	/**
	 * @return bool
	 **/
	public static function skipJs()
	{
		return isset(self::$skip) && empty(self::$skip);
	}


	/**
	 * @return bool
	 **/
	public static function skipHtml()
	{
		return empty(self::$skip);
	}


	/**
	 * @param string
	 * @return string
	 */
	public static function checksum($str)
	{
		$encoded = rtrim(base64_encode(md5($str, TRUE)), '=');
		$encoded = strtr($encoded, array(
			'/' => '_',
			'+' => '-',
		));

		return $encoded;
	}


	public function macroPluginEnd(MacroNode $node, PhpWriter $writer)
	{
		$tokenizer = clone $node->tokenizer;
		$tokenizer->reset();

		$code = '';
		$plugin = '';
		$rest = '';
		$format = 0;
		$tokens = NULL;
		$depth = 0;

		while ($token = $tokenizer->nextToken()) {
			$isLast = !$tokenizer->isNext();

			if ($token[0]===';') {
			} elseif (isset($tokens)) {
				if ($token[0]==='[') {
					$depth++;

				} elseif ($token[0]===']') {
					$depth--;
				}

				if ($depth===0 && $token[2]===MacroTokens::T_VARIABLE) {
					if ($tokenizer->isPrev(',') && ($isLast || $tokenizer->isNext(MacroTokens::T_WHITESPACE, ',', ';'))) {
						$tokens->tokens[] = [ltrim($token[0], '$'), $token[1], MacroTokens::T_SYMBOL];
						$tokens->tokens[] = ['=>', $token[1], MacroTokens::T_CHAR];
					}
				}

				$tokens->tokens[] = $token;

			} elseif ($token[0]==='=>') {
				$format = 1;
				$tokens = clone $node->tokenizer;
				$tokens->position = -1;
				$tokens->tokens = $tokenizer->nextUntil(';');
				$token = $tokenizer->nextToken();
				$isLast = !$tokenizer->isNext();

			} elseif ($token[0]===',') {
				$tokenizer->nextAll(MacroTokens::T_WHITESPACE);

				$format = 2;
				$tokens = clone $node->tokenizer;
				$tokens->position = -1;

				$isRest = FALSE;
				if ($tokens->tokens = $tokenizer->nextAll('...')) {
					$isRest = TRUE;

				} elseif (count($tokens->tokens = $tokenizer->nextAll('.'))===3) {
					$isRest = TRUE;
				}

				if ($isRest && $tokenizer->isNext(MacroTokens::T_VARIABLE)) {
					$tokens->tokens = [];
					$rest = $tokenizer->joinUntil(',', ';');
					$token = $tokenizer->nextToken();
					$isLast = !$tokenizer->isNext();
				}

			} elseif (trim($token[0])) {
				if ($token[0]==='{') {
					$plugin .= '".(';

				} elseif ($token[0]==='}') {
					$plugin .= ')."';

				} else {
					$plugin .= trim($token[0]);
				}
			}

			if ($token[0]===';' || $isLast) {
				$code .= "\"$plugin\" => ";
				$code .= ($rest ? "$rest + " : '');
				$code .= ($format===0 ? 'NULL' : '');
				$code .= ($format===1 ? 'current(' : '');
				$code .= ($format ? $writer->formatArray($tokens) : '');
				$code .= ($format===1 ? ')' : '');
				$code .= $isLast ? '' : ', ';

				$plugin = '';
				$rest = '';
				$format = 0;
				$tokens = NULL;
				$depth = 0;
			}
		}

		$node->openingCode = '<?php if (!PluginMacro::skipJs()) echo PluginMacro::initCode($this->global->plugins, array('.$code.')); ?>';
		$node->attrCode = '<?php if (!PluginMacro::skipJs()) { ?> data-'.$node->name.'="<?php echo $this->global->plugins; ?>"<?php } ?>';
		$node->closingCode = '<?php unset($this->global->plugins) ?>';
	}


	public function macroLabelEnd(MacroNode $node, PhpWriter $writer)
	{
		$id = $node->args ? $writer->formatWord($node->args) : '__FILE__ . __LINE__';

		$content = $node->content;
		$content = preg_replace("#n:q[0-9]+q#", "n:q0q", $content);
		$content = md5($content);
		$content = $writer->formatWord($content);

		$node->attrCode = ' data-'.$node->name.'="<?php echo PluginMacro::checksum('.$id.' . '.$content.');
?>"';
	}


	public function macroLayerEnd(MacroNode $node, PhpWriter $writer)
	{
		$params = array_map(function($arg) {
			$arg = trim($arg);

			if ($arg[0]==='$') {
				return "(isset($arg) ? strval($arg) : '')";

			} else {
				return "\$presenter->getParameter('$arg', '')";
			}
		}, array_filter(explode(',', $node->args)));

		array_unshift($params, 'PluginMacro::routeToMask($presenter->getName())');

		$code[] = 'PluginMacro::checksum("'.md5($node->content).'" . __FILE__ . __LINE__)';
		$code[] = '$presenter->getName().":".($presenter->getAction()==="default" ? "" : $presenter->getAction())';
		$code[] = 'PluginMacro::checksum(' . implode('.', $params) . ')';

		$node->attrCode = ' data-'.$node->name.'="<?php echo '. implode('."#".', $code) . '
?>"';
	}


	/**
	 * @param &string
	 * @param array
	 * @return string
	 **/
	public static function initCode(&$pluginCode, array $params)
	{
		$pluginCode = '';
		$code = '';
		foreach ($params as $plugin => $values) {
			$pluginCode .= "$plugin:";

			if ($values && is_array($values) && array_keys($values)!==range(0, count($values)-1)) {
				$values = (object) $values;
			}

			if (isset($values)) {
				$values = json_encode($values);
				$escaped = htmlSpecialChars($values, ENT_QUOTES, 'UTF-8');

				if (strlen($escaped)<100 && strpos($escaped, '$')===FALSE) {
					$pluginCode .= $escaped;

				} else {
					$pluginCode .= $id = uniqid('p_');
					$code .= "<script type=\"data-plugin/$id\">$values</script>";
				}
			}

			$pluginCode .= '$';
		}

		return $code;
	}



	/**
	 * {skip ...}
	 */
	public function macroSkip(MacroNode $node, PhpWriter $writer)
	{
		return $writer->write('if (PluginMacro::skipHtml()) {');
	}


	/**
	 * {/skip ...}
	 */
	public function macroSkipEnd(MacroNode $node, PhpWriter $writer)
	{
		if (preg_match('#^.*? n:\w+>\n?#s', $node->content, $m1) && preg_match('#^.*>[^<]+/\<#Us', strrev($node->content), $m2)) {
			$node->closingCode .= "<?php } ?>" . strrev($m2[0]);
			$node->content = substr($node->content, strlen($m1[0]), -strlen($m2[0]));
			$node->openingCode = $m1[0] . $node->openingCode;

		} else {
			return $writer->write('}');
		}
	}


	/**
	 * @param string
	 * @return string
	 */
	public static function routeToMask($route)
	{
		foreach (self::$masks as $mask) {
			$mask = (array) self::createRouteMask(explode(':', $mask));
			$parts = explode(':', $route);
			$arbitrary = FALSE;

			for ($i=0; $i<count($parts); $i++) {
				if (isset($mask[$parts[$i]])) {
					$arbitrary = FALSE;
					$mask = $mask[$parts[$i]];

				} elseif (isset($mask['%'])) {
					$arbitrary = FALSE;
					$mask = $mask['%'];

				} elseif (isset($mask['*'])) {
					$parts[$i] = '*';
					$mask = $mask['*'];
					$arbitrary = TRUE;

				} elseif ($arbitrary) {
					$parts[$i] = '*';

				} else {
					continue 2;
				}
			}

			if ($mask===TRUE) {
				return implode(':', $parts);
			}
		}

		return $route;
	}


	/**
	 * @param array
	 * @return array
	 **/
	private static function createRouteMask(array $parts)
	{
		return empty($parts) ?: array_fill_keys(explode('|', array_shift($parts)), self::createRouteMask($parts));
	}

}
