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

	/** @var string */
	private static $code = '';

	/** @var bool|NULL */
	public static $skip = NULL;


	public static function install(Compiler $compiler)
	{
		$me = new self($compiler);

		$me->addMacro('plugin', NULL, [$me, 'macroPluginEnd']);
		$me->addMacro('skip', [$me, 'macroSkip'], [$me, 'macroEndSkip']);
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
				$plugin .= trim($token[0]);
			}

			if ($token[0]===';' || $isLast) {
				$code .= "'$plugin' => ";
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

		$node->openingCode = '<?php $_l->plugins = array('.$code.'); $_l->props[] = isset($props) ? $props : NULL; $props = reset($_l->plugins); if (!PluginMacro::skipJs()) echo PluginMacro::initCode($_l->plugins); ?>';
		$node->closingCode = '<?php $props = array_pop($_l->props); ?>';
		$node->attrCode = '<?php if (!PluginMacro::skipJs()) { ?> data-plugin="<?php echo PluginMacro::pluginCode(); ?>"<?php } ?>';
	}


	/**
	 * @param array
	 * @return string
	 **/
	public static function initCode(array $params)
	{
		self::$code = '';

		$code = '';
		foreach ($params as $plugin => $values) {
			self::$code .= "$plugin:";

			if ($values && is_array($values) && array_keys($values)!==range(0, count($values)-1)) {
				$values = (object) $values;
			}

			if (isset($values)) {
				$values = json_encode($values);
				$escaped = htmlSpecialChars($values, ENT_QUOTES, 'UTF-8');

				if (strlen($escaped)<100 && strpos($escaped, '$')===FALSE) {
					self::$code .= $escaped;

				} else {
					self::$code .= $id = uniqid('p_');
					$code .= "var $id = $values;";
				}
			}

			self::$code .= '$';
		}

		return empty($code) ? "" : "<script type=\"text/javascript\">$code</script>";
	}


	/**
	 * @return string
	 **/
	public static function pluginCode()
	{
		return self::$code;
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
	public function macroEndSkip(MacroNode $node, PhpWriter $writer)
	{
		if (preg_match('#^.*? n:\w+>\n?#s', $node->content, $m1) && preg_match('#^.*>[^<]+/\<#Us', strrev($node->content), $m2)) {
			$node->closingCode .= "<?php } ?>" . strrev($m2[0]);
			$node->content = substr($node->content, strlen($m1[0]), -strlen($m2[0]));
			$node->openingCode = $m1[0] . $node->openingCode;

		} else {
			return $writer->write('}');
		}
	}

}
