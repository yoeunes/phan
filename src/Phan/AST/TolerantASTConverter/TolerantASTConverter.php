<?php declare(strict_types=1);

namespace Phan\AST\TolerantASTConverter;

use AssertionError;
use ast;
use Error;
use InvalidArgumentException;
use Microsoft\PhpParser;
use Microsoft\PhpParser\Diagnostic;
use Microsoft\PhpParser\DiagnosticsProvider;
use Microsoft\PhpParser\FilePositionMap;
use Microsoft\PhpParser\MissingToken;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;
use RuntimeException;

// If php-ast isn't loaded already, then load this file to generate equivalent
// class, constant, and function definitions.
if (!class_exists('\ast\Node')) {
    require_once __DIR__ . '/ast_shim.php';
}

/**
 * Source: https://github.com/TysonAndre/tolerant-php-parser-to-php-ast
 *
 * Uses Microsoft/tolerant-php-parser to create an instance of ast\Node.
 * Useful if the php-ast extension isn't actually installed.
 *
 * @author Tyson Andre
 *
 * TODO: Don't need to pass in $start_line for many of these functions
 *
 * This is implemented as a collection of static methods for performance,
 * but functionality is provided through instance methods.
 * (The private methods may become instance methods if the performance impact is negligible
 * in PHP and HHVM)
 *
 * The instance methods set all of the options (static variables)
 * each time they are invoked,
 * so it's possible to have multiple callers use this without affecting each other.
 *
 * Compatibility: PHP 7.0-7.2
 *
 * ----------------------------------------------------------------------------
 *
 *
 * License for TolerantASTConverter.php:
 *
 * The MIT License (MIT)
 *
 * Copyright (c) 2017-2018 Tyson Andre
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
class TolerantASTConverter
{
    // The latest stable version of php-ast.
    // For something > 50, update the library's release.
    const AST_VERSION = 50;

    // The versions that this supports
    const SUPPORTED_AST_VERSIONS = [self::AST_VERSION];

    const _IGNORED_STRING_TOKEN_KIND_SET = [
        TokenKind::OpenBraceDollarToken => true,
        TokenKind::OpenBraceToken => true,
        TokenKind::DollarOpenBraceToken => true,
        TokenKind::CloseBraceToken => true,
    ];

    // If this environment variable is set, this will throw.
    // (For debugging, may be removed in the future)
    const ENV_AST_THROW_INVALID = 'AST_THROW_INVALID';

    /**
     * @var int - A version in SUPPORTED_AST_VERSIONS
     */
    protected static $php_version_id_parsing = PHP_VERSION_ID;

    /**
     * @var int - Internal counter for declarations, to generate __declId in `\ast\Node`s for declarations.
     */
    protected static $decl_id = 0;

    /** @var bool */
    protected static $should_add_placeholders = false;

    /** @var string */
    protected static $file_contents = '';

    /** @var FilePositionMap */
    protected static $file_position_map;

    /** @var bool */
    private static $parse_all_doc_comments = false;

    /** @var bool Sets equivalent static option in self::_start_parsing() */
    protected $instance_should_add_placeholders = false;

    /**
     * @var int can be used to tweak behavior for compatibility.
     * Set to a newer version to support comments on class constants, etc.
     */
    protected $instance_php_version_id_parsing = PHP_VERSION_ID;

    /**
     * @var bool can be used to force all doc comments to be parsed
     */
    private $instance_parse_all_doc_comments = false;

    // No-op.
    public function __construct()
    {
    }

    /** @return void */
    public function setShouldAddPlaceholders(bool $value)
    {
        $this->instance_should_add_placeholders = $value;
    }

    /** @return void */
    public function setPHPVersionId(int $value)
    {
        $this->instance_php_version_id_parsing = $value;
    }

    /**
     * Parse all doc comments, even the ones the current php version's php-ast would be incapable of providing.
     * @return void
     */
    public function setParseAllDocComments(bool $value)
    {
        $this->instance_parse_all_doc_comments = $value;
    }

    /**
     * @param Diagnostic[] &$errors @phan-output-reference
     * @return \ast\Node
     * @throws InvalidArgumentException if the requested AST version is invalid.
     */
    public function parseCodeAsPHPAST(string $file_contents, int $version, array &$errors = [])
    {
        if (!\in_array($version, self::SUPPORTED_AST_VERSIONS)) {
            throw new \InvalidArgumentException(sprintf("Unexpected version: want %s, got %d", \implode(', ', self::SUPPORTED_AST_VERSIONS), $version));
        }
        // Aside: this can be implemented as a stub.
        $parser_node = static::phpParserParse($file_contents, $errors);
        return $this->phpParserToPhpast($parser_node, $version, $file_contents);
    }

    /**
     * @return PhpParser\Node
     */
    public static function phpParserParse(string $file_contents, array &$errors = null) : PhpParser\Node
    {
        $parser = new Parser();  // TODO: In php 7.3, we might need to provide a version, due to small changes in lexing?
        $result = $parser->parseSourceFile($file_contents);
        $errors = DiagnosticsProvider::getDiagnostics($result);
        return $result;
    }


    /**
     * Visible for testing
     *
     * @param PhpParser\Node $parser_node
     * @param int $ast_version
     * @param string $file_contents
     * @return ast\Node
     * @throws InvalidArgumentException if the provided AST version isn't valid
     */
    public function phpParserToPhpast(PhpParser\Node $parser_node, int $ast_version, string $file_contents)
    {
        if (!\in_array($ast_version, self::SUPPORTED_AST_VERSIONS)) {
            throw new \InvalidArgumentException(sprintf("Unexpected version: want %s, got %d", implode(', ', self::SUPPORTED_AST_VERSIONS), $ast_version));
        }
        $this->startParsing($file_contents);
        $stmts = static::phpParserNodeToAstNode($parser_node);
        // return static::normalizeNamespaces($stmts);
        return $stmts;
    }

    /** @return void */
    protected function startParsing(string $file_contents)
    {
        self::$decl_id = 0;
        self::$should_add_placeholders = $this->instance_should_add_placeholders;
        self::$php_version_id_parsing = $this->instance_php_version_id_parsing;
        self::$parse_all_doc_comments = $this->instance_parse_all_doc_comments;
        self::$file_position_map = new FilePositionMap($file_contents);
        // $file_contents required for looking up line numbers.
        // TODO: Other data structures?
        self::$file_contents = $file_contents;
    }

    /** @param null|bool|int|string|PhpParser\Node|Token|array $n */
    protected static function debugDumpNodeOrToken($n) : string
    {
        if (\is_scalar($n)) {
            return var_export($n, true);
        }
        if (!\is_array($n)) {
            $n = [$n];
        }
        $result = [];
        foreach ($n as $e) {
            $dumper = new NodeDumper(self::$file_contents);
            $dumper->setIncludeTokenKind(true);
            $result[] = $dumper->dumpTreeAsString($e);
        }
        return implode("\n", $result);
    }

    /**
     * @param Token|PhpParser\Node[]|PhpParser\Node\StatementNode $parser_nodes
     *        This is represented as a single node for `if` with a colon (macro style)
     * @param ?int $lineno
     * @param bool $return_null_on_empty (return null if non-array (E.g. semicolon is seen))
     * @return ?ast\Node
     * @throws RuntimeException if the statement list is invalid
     */
    private static function phpParserStmtlistToAstNode($parser_nodes, $lineno, bool $return_null_on_empty = false)
    {
        if ($parser_nodes instanceof PhpParser\Node\Statement\CompoundStatementNode) {
            $parser_nodes = $parser_nodes->statements;
        } elseif ($parser_nodes instanceof PhpParser\Node\StatementNode) {
            if ($parser_nodes instanceof PhpParser\Node\Statement\EmptyStatement) {
                $parser_nodes = [];
            } else {
                $parser_nodes = [$parser_nodes];
            }
        } elseif ($parser_nodes instanceof Token) {
            if ($parser_nodes->kind === TokenKind::SemicolonToken) {
                if ($return_null_on_empty) {
                    return null;
                }
                return new ast\Node(
                    ast\AST_STMT_LIST,
                    0,
                    [],
                    $lineno ?? 0
                );
            }
        }

        if (!\is_array($parser_nodes)) {
            throw new RuntimeException("Unexpected type for statements: " . static::debugDumpNodeOrToken($parser_nodes));
        }
        $children = [];
        foreach ($parser_nodes as $parser_node) {
            try {
                $child_node = static::phpParserNodeToAstNode($parser_node);
            } catch (InvalidNodeException $_) {
                continue;
            }
            if (\is_array($child_node)) {
                // Echo_ returns multiple children.
                foreach ($child_node as $child_node_part) {
                    $children[] = $child_node_part;
                }
            } elseif (!\is_null($child_node)) {
                $children[] = $child_node;
            }
        }
        if (!\is_int($lineno)) {
            foreach ($parser_nodes as $parser_node) {
                $child_node_line = static::getEndLine($parser_node);
                if ($child_node_line > 0) {
                    $lineno = $child_node_line;
                    break;
                }
            }
        }
        return new ast\Node(ast\AST_STMT_LIST, 0, $children, $lineno ?? 0);
    }

    private static function phpParserExprListToExprList(PhpParser\Node\DelimitedList\ExpressionList $expressions_list, int $lineno) : ast\Node
    {
        $children = [];
        $expressions_children = $expressions_list->children;
        foreach ($expressions_children as $expr) {
            if ($expr instanceof Token && $expr->kind === TokenKind::CommaToken) {
                continue;
            }
            $child_node = static::phpParserNodeToAstNode($expr);
            if (\is_array($child_node)) {
                // Echo_ returns multiple children in php-ast
                foreach ($child_node as $child_node_part) {
                    $children[] = $child_node_part;
                }
            } elseif (!\is_null($child_node)) {
                $children[] = $child_node;
            }
        }
        foreach ($expressions_children as $parser_node) {
            $child_node_line = static::getEndLine($parser_node);
            if ($child_node_line > 0) {
                $lineno = $child_node_line;
                break;
            }
        }
        return new ast\Node(
            ast\AST_EXPR_LIST,
            0,
            $children,
            $lineno
        );
    }

    /**
     * @param PhpParser\Node|Token $n - The node from PHP-Parser
     * @return ast\Node|ast\Node[]|string|int|float|bool|null - whatever ast\parse_code would return as the equivalent.
     * @throws InvalidArgumentException if Phan doesn't know what $n is
     */
    protected static function phpParserNonValueNodeToAstNode($n)
    {
        static $callback_map;
        static $fallback_closure;
        if (\is_null($callback_map)) {
            $callback_map = static::initHandleMap();
            /**
             * @param PhpParser\Node|Token $n
             * @return ast\Node - Not a real node, but a node indicating the TODO
             * @throws InvalidArgumentException for invalid node classes
             * @throws Error if the environment variable AST_THROW_INVALID is set (for debugging)
             */
            $fallback_closure = function ($n, int $unused_start_line) {
                if (!($n instanceof PhpParser\Node) && !($n instanceof Token)) {
                    throw new \InvalidArgumentException("Invalid type for node: " . (\is_object($n) ? \get_class($n) : \gettype($n)) . ": " . static::debugDumpNodeOrToken($n));
                }
                return static::astStub($n);
            };
        }
        $callback = $callback_map[\get_class($n)] ?? $fallback_closure;
        return $callback($n, self::getStartLine($n));
    }

    /**
     * @param PhpParser\Node|Token $n - The node from PHP-Parser
     * @return ast\Node|ast\Node[]|string|int|float|bool - whatever ast\parse_code would return as the equivalent.
     * @throws InvalidNodeException when self::$should_add_placeholders is false, like many of these methods.
     */
    protected static function phpParserNodeToAstNodeOrPlaceholderExpr($n)
    {
        if (!self::$should_add_placeholders) {
            return static::phpParserNodeToAstNode($n);
        }
        try {
            return static::phpParserNodeToAstNode($n);
        } catch (InvalidNodeException $_) {
            return static::newPlaceholderExpression($n);
        }
    }

    /**
     * @param PhpParser\Node|Token $n - The node from PHP-Parser
     * @return ast\Node|ast\Node[]|string|int|float|null - whatever ast\parse_code would return as the equivalent.
     */
    protected static function phpParserNodeToAstNode($n)
    {
        static $callback_map;
        static $fallback_closure;
        if (\is_null($callback_map)) {
            $callback_map = static::initHandleMap();
            /**
             * @param PhpParser\Node|Token $n
             * @return ast\Node - Not a real node, but a node indicating the TODO
             * @throws InvalidArgumentException for invalid node classes
             * @throws Error if the environment variable AST_THROW_INVALID is set to debug.
             */
            $fallback_closure = function ($n, int $unused_start_line) {
                if (!($n instanceof PhpParser\Node) && !($n instanceof Token)) {
                    throw new \InvalidArgumentException("Invalid type for node: " . (\is_object($n) ? \get_class($n) : \gettype($n)) . ": " . static::debugDumpNodeOrToken($n));
                }

                return static::astStub($n);
            };
        }
        $callback = $callback_map[\get_class($n)] ?? $fallback_closure;
        $result = $callback($n, self::$file_position_map->getStartLine($n));
        if (($result instanceof ast\Node) && $result->kind === ast\AST_NAME) {
            return new ast\Node(ast\AST_CONST, 0, ['name' => $result], $result->lineno);
        }
        return $result;
    }

    /**
     * @param PhpParser\Node|Token $n
     * @throws InvalidNodeException if this was called on an unexpected type
     */
    final protected static function getStartLine($n) : int
    {
        if (\is_object($n)) {
            return self::$file_position_map->getStartLine($n);
        }
        throw new InvalidNodeException();
    }

    /**
     * @param ?PhpParser\Node|?Token $n
     * @throws InvalidNodeException if this was called on an unexpected type
     */
    final protected static function getEndLine($n) : int
    {
        if (!\is_object($n)) {
            if (\is_null($n)) {
                return 0;
            }
            throw new InvalidNodeException();
        }
        return self::$file_position_map->getEndLine($n);
    }

    /**
     * This returns an array of values mapping class names to the closures which converts them to a scalar or ast\Node
     *
     * Why not a switch? Switches are slow until php 7.2, and there are dozens of class names to handle.
     *
     * - In php <= 7.1, the interpreter would loop through all possible cases, and compare against the value one by one.
     * - There are a lot of local variables to look at.
     *
     * @return \Closure[]
     */
    protected static function initHandleMap() : array
    {
        $closures = [
            /** @return ?ast\Node */
            'Microsoft\PhpParser\Node\SourceFileNode' => function (PhpParser\Node\SourceFileNode $n, int $start_line) {
                return static::phpParserStmtlistToAstNode($n->statementList, $start_line, false);
            },
            /** @return mixed */
            'Microsoft\PhpParser\Node\Expression\ArgumentExpression' => function (PhpParser\Node\Expression\ArgumentExpression $n, int $start_line) {
                $result = static::phpParserNodeToAstNode($n->expression);
                if ($n->dotDotDotToken !== null) {
                    return new ast\Node(ast\AST_UNPACK, 0, ['expr' => $result], $start_line);
                }
                return $result;
            },
            'Microsoft\PhpParser\Node\Expression\SubscriptExpression' => function (PhpParser\Node\Expression\SubscriptExpression $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_DIM, 0, [
                    'expr' => static::phpParserNodeToAstNode($n->postfixExpression),
                    'dim' => $n->accessExpression !== null ? static::phpParserNodeToAstNode($n->accessExpression) : null,
                ], $start_line);
            },
            /** @return ?ast\Node */
            'Microsoft\PhpParser\Node\Expression\AssignmentExpression' => function (PhpParser\Node\Expression\AssignmentExpression $n, int $start_line) {
                try {
                    $var_node = static::phpParserNodeToAstNode($n->leftOperand);
                } catch (InvalidNodeException $_) {
                    if (self::$should_add_placeholders) {
                        $var_node = new ast\Node(ast\AST_VAR, 0, ['name' => '__INCOMPLETE_VARIABLE__'], $start_line);
                    } else {
                        // convert `;= $b;` to `;$b;`
                        return static::phpParserNodeToAstNode($n->rightOperand);
                    }
                }
                $expr_node = static::phpParserNodeToAstNodeOrPlaceholderExpr($n->rightOperand);
                // FIXME switch on $n->kind
                return static::astNodeAssign(
                    $var_node,
                    $expr_node,
                    $start_line,
                    $n->byRef !== null
                );
            },
            /**
             * @return ast\Node|string|float|int (can return a non-Node if the left or right hand side could not be parsed
             */
            'Microsoft\PhpParser\Node\Expression\BinaryExpression' => function (PhpParser\Node\Expression\BinaryExpression $n, int $start_line) {
                static $lookup = [
                    TokenKind::AmpersandAmpersandToken              => ast\flags\BINARY_BOOL_AND,
                    TokenKind::AmpersandToken                       => ast\flags\BINARY_BITWISE_AND,
                    TokenKind::AndKeyword                           => ast\flags\BINARY_BOOL_AND,
                    TokenKind::AsteriskAsteriskToken                => ast\flags\BINARY_POW,
                    TokenKind::AsteriskToken                        => ast\flags\BINARY_MUL,
                    TokenKind::BarBarToken                          => ast\flags\BINARY_BOOL_OR,
                    TokenKind::BarToken                             => ast\flags\BINARY_BITWISE_OR,
                    TokenKind::CaretToken                           => ast\flags\BINARY_BITWISE_XOR,
                    TokenKind::DotToken                             => ast\flags\BINARY_CONCAT,
                    TokenKind::EqualsEqualsEqualsToken              => ast\flags\BINARY_IS_IDENTICAL,
                    TokenKind::EqualsEqualsToken                    => ast\flags\BINARY_IS_EQUAL,
                    TokenKind::ExclamationEqualsEqualsToken         => ast\flags\BINARY_IS_NOT_IDENTICAL,
                    TokenKind::ExclamationEqualsToken               => ast\flags\BINARY_IS_NOT_EQUAL,
                    TokenKind::GreaterThanEqualsToken               => ast\flags\BINARY_IS_GREATER_OR_EQUAL,
                    TokenKind::GreaterThanGreaterThanToken          => ast\flags\BINARY_SHIFT_RIGHT,
                    TokenKind::GreaterThanToken                     => ast\flags\BINARY_IS_GREATER,
                    TokenKind::LessThanEqualsGreaterThanToken       => ast\flags\BINARY_SPACESHIP,
                    TokenKind::LessThanEqualsToken                  => ast\flags\BINARY_IS_SMALLER_OR_EQUAL,
                    TokenKind::LessThanLessThanToken                => ast\flags\BINARY_SHIFT_LEFT,
                    TokenKind::LessThanToken                        => ast\flags\BINARY_IS_SMALLER,
                    TokenKind::MinusToken                           => ast\flags\BINARY_SUB,
                    TokenKind::OrKeyword                            => ast\flags\BINARY_BOOL_OR,
                    TokenKind::PercentToken                         => ast\flags\BINARY_MOD,
                    TokenKind::PlusToken                            => ast\flags\BINARY_ADD,
                    TokenKind::QuestionQuestionToken                => ast\flags\BINARY_COALESCE,
                    TokenKind::SlashToken                           => ast\flags\BINARY_DIV,
                    TokenKind::XorKeyword                           => ast\flags\BINARY_BOOL_XOR,
                ];
                static $assign_lookup = [
                    TokenKind::AmpersandEqualsToken                 => \ast\flags\BINARY_BITWISE_AND,
                    TokenKind::AsteriskAsteriskEqualsToken          => \ast\flags\BINARY_POW,
                    TokenKind::AsteriskEqualsToken                  => \ast\flags\BINARY_MUL,
                    TokenKind::BarEqualsToken                       => \ast\flags\BINARY_BITWISE_OR,
                    TokenKind::CaretEqualsToken                     => \ast\flags\BINARY_BITWISE_XOR,
                    TokenKind::DotEqualsToken                       => \ast\flags\BINARY_CONCAT,
                    TokenKind::MinusEqualsToken                     => \ast\flags\BINARY_SUB,
                    TokenKind::PercentEqualsToken                   => \ast\flags\BINARY_MOD,
                    TokenKind::PlusEqualsToken                      => \ast\flags\BINARY_ADD,
                    TokenKind::SlashEqualsToken                     => \ast\flags\BINARY_DIV,
                    TokenKind::GreaterThanGreaterThanEqualsToken    => ast\flags\BINARY_SHIFT_RIGHT,
                    TokenKind::LessThanLessThanEqualsToken          => ast\flags\BINARY_SHIFT_LEFT,
                ];
                $kind = $n->operator->kind;
                if ($kind === TokenKind::InstanceOfKeyword) {
                    return new ast\Node(ast\AST_INSTANCEOF, 0, [
                        'expr'  => static::phpParserNodeToAstNode($n->leftOperand),
                        'class' => static::phpParserNonValueNodeToAstNode($n->rightOperand),
                    ], $start_line);
                }
                $ast_kind = $lookup[$kind] ?? null;
                if ($ast_kind === null) {
                    $ast_kind = $assign_lookup[$kind] ?? null;
                    if ($ast_kind === null) {
                        throw new AssertionError("missing $kind (" . Token::getTokenKindNameFromValue($kind) . ")");
                    }
                    return static::astNodeAssignop($ast_kind, $n, $start_line);
                }
                return static::astNodeBinaryop($ast_kind, $n, $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\UnaryOpExpression' => function (PhpParser\Node\Expression\UnaryOpExpression $n, int $start_line) : ast\Node {
                static $lookup = [
                    TokenKind::TildeToken                   => ast\flags\UNARY_BITWISE_NOT,
                    TokenKind::MinusToken                   => ast\flags\UNARY_MINUS,
                    TokenKind::PlusToken                    => ast\flags\UNARY_PLUS,
                    TokenKind::ExclamationToken             => ast\flags\UNARY_BOOL_NOT,
                ];
                $kind = $n->operator->kind;
                $ast_kind = $lookup[$kind] ?? null;
                if ($ast_kind === null) {
                    throw new AssertionError("missing $kind(" . Token::getTokenKindNameFromValue($kind) . ")");
                }
                return static::astNodeUnaryOp($ast_kind, static::phpParserNodeToAstNode($n->operand), $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\CastExpression' => function (PhpParser\Node\Expression\CastExpression $n, int $start_line) : ast\Node {
                static $lookup = [
                    TokenKind::ArrayCastToken   => ast\flags\TYPE_ARRAY,
                    TokenKind::BoolCastToken    => ast\flags\TYPE_BOOL,
                    TokenKind::DoubleCastToken  => ast\flags\TYPE_DOUBLE,
                    TokenKind::IntCastToken     => ast\flags\TYPE_LONG,
                    TokenKind::ObjectCastToken  => ast\flags\TYPE_OBJECT,
                    TokenKind::StringCastToken  => ast\flags\TYPE_STRING,
                    TokenKind::UnsetCastToken   => ast\flags\TYPE_NULL,
                ];
                $kind = $n->castType->kind;
                $ast_kind = $lookup[$kind] ?? null;
                if ($ast_kind === null) {
                    throw new AssertionError("missing $kind");
                }
                return static::astNodeCast($ast_kind, $n, $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\AnonymousFunctionCreationExpression' => function (PhpParser\Node\Expression\AnonymousFunctionCreationExpression $n, int $start_line) : ast\Node {
                $ast_return_type = static::phpParserTypeToAstNode($n->returnType, static::getEndLine($n->returnType) ?: $start_line);
                if (($ast_return_type->children['name'] ?? null) === '') {
                    $ast_return_type = null;
                }
                if ($n->questionToken !== null && $ast_return_type !== null) {
                    $ast_return_type = new ast\Node(ast\AST_NULLABLE_TYPE, 0, ['type' => $ast_return_type], $start_line);
                }
                return static::astDeclClosure(
                    $n->byRefToken !== null,
                    $n->staticModifier !== null,
                    static::phpParserParamsToAstParams($n->parameters, $start_line),
                    static::phpParserClosureUsesToAstClosureUses($n->anonymousFunctionUseClause->useVariableNameList ?? null, $start_line),
                    static::phpParserStmtlistToAstNode($n->compoundStatementOrSemicolon->statements, self::getStartLine($n->compoundStatementOrSemicolon), false),
                    $ast_return_type,
                    $start_line,
                    static::getEndLine($n),
                    static::resolveDocCommentForClosure($n)
                );
            },
            /**
             * @return ?ast\Node
             * @throws InvalidNodeException if the resulting AST would not be analyzable by Phan
             */
            'Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression' => function (PhpParser\Node\Expression\ScopedPropertyAccessExpression $n, int $start_line) {
                $member_name = $n->memberName;
                if ($member_name instanceof PhpParser\Node\Expression\Variable) {
                    return new ast\Node(
                        ast\AST_STATIC_PROP,
                        0,
                        [
                            'class' => static::phpParserNonValueNodeToAstNode($n->scopeResolutionQualifier),
                            'prop' => static::phpParserNodeToAstNode($member_name->name),
                        ],
                        $start_line
                    );
                } else {
                    if ($member_name instanceof Token) {
                        if (\get_class($member_name) !== Token::class) {
                            if (self::$should_add_placeholders) {
                                $member_name = '__INCOMPLETE_CLASS_CONST__';
                            } else {
                                throw new InvalidNodeException();
                            }
                        } else {
                            $member_name = static::tokenToString($member_name);
                        }
                    } else {
                        // E.g. Node\Expression\BracedExpression
                        throw new InvalidNodeException();
                    }
                    return static::phpParserClassconstfetchToAstClassconstfetch($n->scopeResolutionQualifier, $member_name, $start_line);
                }
            },
            'Microsoft\PhpParser\Node\Expression\CloneExpression' => function (PhpParser\Node\Expression\CloneExpression $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_CLONE, 0, ['expr' => static::phpParserNodeToAstNode($n->expression)], $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\ErrorControlExpression' => function (PhpParser\Node\Expression\ErrorControlExpression $n, int $start_line) : ast\Node {
                return static::astNodeUnaryOp(ast\flags\UNARY_SILENCE, static::phpParserNodeToAstNode($n->operand), $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\EmptyIntrinsicExpression' => function (PhpParser\Node\Expression\EmptyIntrinsicExpression $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_EMPTY, 0, ['expr' => static::phpParserNodeToAstNode($n->expression)], $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\EvalIntrinsicExpression' => function (PhpParser\Node\Expression\EvalIntrinsicExpression $n, int $start_line) : ast\Node {
                return static::astNodeEval(
                    static::phpParserNodeToAstNode($n->expression),
                    $start_line
                );
            },
            /** @return string|ast\Node */
            'Microsoft\PhpParser\Token' => function (PhpParser\Token $token, int $start_line) {
                $kind = $token->kind;
                $str = static::tokenToString($token);
                if ($kind === TokenKind::StaticKeyword) {
                    return new ast\Node(ast\AST_NAME, ast\flags\NAME_NOT_FQ, ['name' => $str], $start_line);
                }
                return $str;
            },
            /**
             * @throws InvalidNodeException
             */
            'Microsoft\PhpParser\MissingToken' => function (PhpParser\MissingToken $unused_node, int $_) {
                throw new InvalidNodeException();
            },
            /**
             * @throws InvalidNodeException
             */
            'Microsoft\PhpParser\SkippedToken' => function (PhpParser\SkippedToken $unused_node, int $_) {
                throw new InvalidNodeException();
            },
            'Microsoft\PhpParser\Node\Expression\ExitIntrinsicExpression' => function (PhpParser\Node\Expression\ExitIntrinsicExpression $n, int $start_line) : ast\Node {
                $expr_node = $n->expression !== null ? static::phpParserNodeToAstNode($n->expression) : null;
                return new ast\Node(ast\AST_EXIT, 0, ['expr' => $expr_node], $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\CallExpression' => function (PhpParser\Node\Expression\CallExpression $n, int $start_line) : ast\Node {
                $callable_expression = $n->callableExpression;
                $arg_list = static::phpParserArgListToAstArgList($n->argumentExpressionList, $start_line);
                if ($callable_expression instanceof PhpParser\Node\Expression\MemberAccessExpression) {  // $a->f()
                    return static::astNodeMethodCall(
                        static::phpParserNonValueNodeToAstNode($callable_expression->dereferencableExpression),
                        static::phpParserNodeToAstNode($callable_expression->memberName),
                        $arg_list,
                        $start_line
                    );
                } elseif ($callable_expression instanceof PhpParser\Node\Expression\ScopedPropertyAccessExpression) {  // a::f()
                    return static::astNodeStaticCall(
                        static::phpParserNonValueNodeToAstNode($callable_expression->scopeResolutionQualifier),
                        static::phpParserNodeToAstNode($callable_expression->memberName),
                        $arg_list,
                        $start_line
                    );
                } else {  // f()
                    return static::astNodeCall(
                        static::phpParserNonValueNodeToAstNode($callable_expression),
                        $arg_list,
                        $start_line
                    );
                }
            },
            'Microsoft\PhpParser\Node\Expression\ScriptInclusionExpression' => function (PhpParser\Node\Expression\ScriptInclusionExpression $n, int $start_line) : ast\Node {
                return static::astNodeInclude(
                    static::phpParserNodeToAstNode($n->expression),
                    $start_line,
                    $n->requireOrIncludeKeyword
                );
            },
            /**
             * @return ?ast\Node
             */
            'Microsoft\PhpParser\Node\Expression\IssetIntrinsicExpression' => function (PhpParser\Node\Expression\IssetIntrinsicExpression $n, int $start_line) {
                $ast_issets = [];
                foreach ($n->expressions->children ?? [] as $var) {
                    if ($var instanceof Token) {
                        if ($var->kind === TokenKind::CommaToken) {
                            continue;
                        } elseif ($var->length === 0) {
                            continue;
                        }
                    }
                    $ast_issets[] = new ast\Node(ast\AST_ISSET, 0, [
                        'var' => static::phpParserNodeToAstNode($var),
                    ], $start_line);
                }
                $e = $ast_issets[0] ?? null;
                for ($i = 1; $i < \count($ast_issets); $i++) {
                    $right = $ast_issets[$i];
                    $e = new ast\Node(
                        ast\AST_BINARY_OP,
                        ast\flags\BINARY_BOOL_AND,
                        [
                            'left' => $e,
                            'right' => $right,
                        ],
                        $e->lineno
                    );
                }
                return $e;
            },
            'Microsoft\PhpParser\Node\Expression\ArrayCreationExpression' => function (PhpParser\Node\Expression\ArrayCreationExpression $n, int $start_line) : ast\Node {
                return static::phpParserArrayToAstArray($n, $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\ListIntrinsicExpression' => function (PhpParser\Node\Expression\ListIntrinsicExpression $n, int $start_line) : ast\Node {
                return static::phpParserListToAstList($n, $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\ObjectCreationExpression' => function (PhpParser\Node\Expression\ObjectCreationExpression $n, int $start_line) : ast\Node {
                $end_line = static::getEndLine($n);
                $class_type_designator = $n->classTypeDesignator;
                if ($class_type_designator instanceof Token && $class_type_designator->kind === TokenKind::ClassKeyword) {
                    // Node of type AST_CLASS
                    $class_node = static::astStmtClass(
                        ast\flags\CLASS_ANONYMOUS,
                        null,
                        $n->classBaseClause !== null ? static::phpParserNonValueNodeToAstNode($n->classBaseClause->baseClass) : null,
                        $n->classInterfaceClause,
                        static::phpParserStmtlistToAstNode($n->classMembers->classMemberDeclarations ?? [], $start_line, false),
                        $start_line,
                        $end_line,
                        $n->getDocCommentText()
                    );
                } else {
                    $class_node = static::phpParserNonValueNodeToAstNode($class_type_designator);
                }
                return new ast\Node(ast\AST_NEW, 0, [
                    'class' => $class_node,
                    'args' => static::phpParserArgListToAstArgList($n->argumentExpressionList, $start_line),
                ], $start_line);
            },
            /** @return mixed */
            'Microsoft\PhpParser\Node\Expression\ParenthesizedExpression' => function (PhpParser\Node\Expression\ParenthesizedExpression $n, int $_) {
                return static::phpParserNodeToAstNode($n->expression);
            },
            'Microsoft\PhpParser\Node\Expression\PrefixUpdateExpression' => function (PhpParser\Node\Expression\PrefixUpdateExpression $n, int $start_line) : ast\Node {
                $type = $n->incrementOrDecrementOperator->kind === TokenKind::PlusPlusToken ? ast\AST_PRE_INC : ast\AST_PRE_DEC;

                return new ast\Node($type, 0, ['var' => static::phpParserNodeToAstNode($n->operand)], $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\PostfixUpdateExpression' => function (PhpParser\Node\Expression\PostfixUpdateExpression $n, int $start_line) : ast\Node {
                $type = $n->incrementOrDecrementOperator->kind === TokenKind::PlusPlusToken ? ast\AST_POST_INC : ast\AST_POST_DEC;

                return new ast\Node($type, 0, ['var' => static::phpParserNodeToAstNode($n->operand)], $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\PrintIntrinsicExpression' => function (PhpParser\Node\Expression\PrintIntrinsicExpression $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_PRINT,
                    0,
                    ['expr' => static::phpParserNodeToAstNode($n->expression)],
                    $start_line
                );
            },
            /** @return ?ast\Node */
            'Microsoft\PhpParser\Node\Expression\MemberAccessExpression' => function (PhpParser\Node\Expression\MemberAccessExpression $n, int $start_line) {
                return static::phpParserMemberAccessExpressionToAstProp($n, $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\TernaryExpression' => function (PhpParser\Node\Expression\TernaryExpression $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_CONDITIONAL,
                    0,
                    [
                        'cond' => static::phpParserNodeToAstNode($n->condition),
                        'true' => $n->ifExpression !== null ? static::phpParserNodeToAstNode($n->ifExpression) : null,
                        'false' => static::phpParserNodeToAstNode($n->elseExpression),
                    ],
                    $start_line
                );
            },
            /**
             * @return ?ast\Node
             * @throws InvalidNodeException if the variable would be unanalyzable
             * TODO: Consider ${''} as a placeholder instead?
             */
            'Microsoft\PhpParser\Node\Expression\Variable' => function (PhpParser\Node\Expression\Variable $n, int $start_line) {
                $name_node = $n->name;
                // Note: there are 2 different ways to handle an Error. 1. Add a placeholder. 2. remove all of the statements in that tree.
                if ($name_node instanceof PhpParser\Node) {
                    $name_node = static::phpParserNodeToAstNode($name_node);
                } elseif ($name_node instanceof Token) {
                    if ($name_node instanceof PhpParser\MissingToken) {
                        if (self::$should_add_placeholders) {
                            $name_node = '__INCOMPLETE_VARIABLE__';
                        } else {
                            throw new InvalidNodeException();
                        }
                    } else {
                        if ($name_node->kind === TokenKind::VariableName) {
                            $name_node = static::variableTokenToString($name_node);
                        } else {
                            $name_node = static::tokenToString($name_node);
                        }
                    }
                }
                return new ast\Node(ast\AST_VAR, 0, ['name' => $name_node], $start_line);
            },
            /**
             * @return ast\Node
             */
            'Microsoft\PhpParser\Node\Expression\BracedExpression' => function (PhpParser\Node\Expression\BracedExpression $n, int $_) {
                return static::phpParserNodeToAstNode($n->expression);
            },
            'Microsoft\PhpParser\Node\Expression\YieldExpression' => function (PhpParser\Node\Expression\YieldExpression $n, int $start_line) : ast\Node {
                $kind = $n->yieldOrYieldFromKeyword->kind === TokenKind::YieldFromKeyword ? ast\AST_YIELD_FROM : ast\AST_YIELD;

                $array_element = $n->arrayElement;
                $element_value = $array_element->elementValue ?? null;
                // Workaround for <= 0.0.5
                // TODO: Remove workaround?
                $ast_expr = ($element_value !== null && !($element_value instanceof MissingToken)) ? static::phpParserNodeToAstNode($array_element->elementValue) : null;
                if ($kind === \ast\AST_YIELD) {
                    $children = [
                        'value' => $ast_expr,
                        'key' => ($array_element->elementKey ?? null) !== null ? static::phpParserNodeToAstNode($array_element->elementKey) : null,
                    ];
                } else {
                    $children = [
                        'expr' => $ast_expr,
                    ];
                }
                return new ast\Node(
                    $kind,
                    0,
                    $children,
                    $start_line
                );
            },
            'Microsoft\PhpParser\Node\ReservedWord' => function (PhpParser\Node\ReservedWord $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_NAME,
                    ast\flags\NAME_NOT_FQ,
                    ['name' => static::tokenToString($n->children)],
                    $start_line
                );
            },
            'Microsoft\PhpParser\Node\QualifiedName' => function (PhpParser\Node\QualifiedName $n, int $start_line) : ast\Node {
                $name_parts = $n->nameParts;
                if (\count($name_parts) === 1) {
                    $part = $name_parts[0];
                    $imploded_parts = static::tokenToString($part);
                    if ($part->kind === TokenKind::Name) {
                        if (\preg_match('@__(LINE|FILE|DIR|FUNCTION|CLASS|TRAIT|METHOD|NAMESPACE)__@i', $imploded_parts) > 0) {
                            return new \ast\Node(
                                ast\AST_MAGIC_CONST,
                                self::_MAGIC_CONST_LOOKUP[\strtoupper($imploded_parts)],
                                [],
                                self::getStartLine($part)
                            );
                        }
                    }
                } else {
                    $imploded_parts = static::phpParserNameToString($n);
                }
                if ($n->globalSpecifier !== null) {
                    $ast_kind = ast\flags\NAME_FQ;
                } elseif (($n->relativeSpecifier->namespaceKeyword ?? null) !== null) {
                    $ast_kind = ast\flags\NAME_RELATIVE;
                } else {
                    $ast_kind = ast\flags\NAME_NOT_FQ;
                }
                return new ast\Node(ast\AST_NAME, $ast_kind, ['name' => $imploded_parts], $start_line);
            },
            'Microsoft\PhpParser\Node\Parameter' => function (PhpParser\Node\Parameter $n, int $start_line) : ast\Node {
                $type_line = static::getEndLine($n->typeDeclaration) ?: $start_line;
                $default_node = $n->default !== null ? static::phpParserNodeToAstNode($n->default) : null;
                return static::astNodeParam(
                    $n->questionToken !== null,
                    $n->byRefToken !== null,
                    $n->dotDotDotToken !== null,
                    static::phpParserTypeToAstNode($n->typeDeclaration, $type_line),
                    static::variableTokenToString($n->variableName),
                    $default_node,
                    $start_line
                );
            },
            /** @return int|float */
            'Microsoft\PhpParser\Node\NumericLiteral' => function (PhpParser\Node\NumericLiteral $n, int $_) {
                $text = static::tokenToString($n->children);
                $as_int = \filter_var($text, FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_OCTAL | FILTER_FLAG_ALLOW_HEX);
                if ($as_int !== false) {
                    return $as_int;
                }
                return (float)$text;
            },
            /**
             * @return ast\Node|string
             */
            'Microsoft\PhpParser\Node\StringLiteral' => function (PhpParser\Node\StringLiteral $n, int $_) {
                $children = $n->children;
                if ($children instanceof Token) {
                    $inner_node = static::parseQuotedString($n);
                } elseif (\count($children) === 0) {
                    $inner_node = '';
                } elseif (\count($children) === 1 && $children[0] instanceof Token) {
                    $inner_node = static::parseQuotedString($n);
                } else {
                    $inner_node_parts = [];
                    foreach ($children as $part) {
                        if ($part instanceof PhpParser\Node) {
                            $inner_node_parts[] = static::phpParserNodeToAstNode($part);
                        } else {
                            $kind = $part->kind;
                            if (\array_key_exists($kind, self::_IGNORED_STRING_TOKEN_KIND_SET)) {
                                continue;
                            }
                            // ($part->kind === TokenKind::EncapsedAndWhitespace)
                            $start_quote_text = static::tokenToString($n->startQuote);
                            $end_quote_text = static::tokenToString($n->endQuote);
                            $raw_string = static::tokenToRawString($part);

                            // Pass in '"\\n"' and get "\n" (somewhat inefficient)
                            $represented_string = StringUtil::parse($start_quote_text . $raw_string . $end_quote_text);
                            $inner_node_parts[] = $represented_string;
                        }
                    }
                    $inner_node = new ast\Node(ast\AST_ENCAPS_LIST, 0, $inner_node_parts, self::getStartLine($children[0]));
                }
                if ($n->startQuote !== null && $n->startQuote->kind === TokenKind::BacktickToken) {
                    return new ast\Node(ast\AST_SHELL_EXEC, 0, ['expr' => $inner_node], self::getStartLine($children[0]));
                    // TODO: verify match
                }
                return $inner_node;
            },
            /** @return mixed - Can return a node or a scalar, depending on the settings */
            'Microsoft\PhpParser\Node\Statement\CompoundStatementNode' => function (PhpParser\Node\Statement\CompoundStatementNode $n, int $_) {
                $children = [];
                foreach ($n->statements as $parser_node) {
                    $child_node = static::phpParserNodeToAstNode($parser_node);
                    if (\is_array($child_node)) {
                        // Echo_ returns multiple children.
                        foreach ($child_node as $child_node_part) {
                            $children[] = $child_node_part;
                        }
                    } elseif (!\is_null($child_node)) {
                        $children[] = $child_node;
                    }
                }
                return $children;
            },
            /**
             * @return int|string|ast\Node|null
             * null if incomplete
             * int|string for no-op scalar statements like `;2;`
             */
            'Microsoft\PhpParser\Node\Statement\ExpressionStatement' => function (PhpParser\Node\Statement\ExpressionStatement $n, int $_) {
                $expression = $n->expression;
                // tolerant-php-parser uses parseExpression(..., $force=true), which can return an array.
                // It is the only thing that uses $force=true at the time of writing.
                if (!\is_object($expression)) {
                    return null;
                }
                return static::phpParserNodeToAstNode($n->expression);
            },
            'Microsoft\PhpParser\Node\Statement\BreakOrContinueStatement' => function (PhpParser\Node\Statement\BreakOrContinueStatement $n, int $start_line) : ast\Node {
                $kind = $n->breakOrContinueKeyword->kind === TokenKind::ContinueKeyword ? ast\AST_CONTINUE : ast\AST_BREAK;
                if ($n->breakoutLevel !== null) {
                    $breakout_level = static::phpParserNodeToAstNode($n->breakoutLevel);
                    if (!\is_int($breakout_level)) {
                        $breakout_level = null;
                    }
                } else {
                    $breakout_level = null;
                }
                return new ast\Node($kind, 0, ['depth' => $breakout_level], $start_line);
            },
            'Microsoft\PhpParser\Node\CatchClause' => function (PhpParser\Node\CatchClause $n, int $start_line) : ast\Node {
                $qualified_name = $n->qualifiedName;
                $catch_inner_list = [];
                // Handle `catch()` syntax error
                if ($qualified_name instanceof PhpParser\Node\QualifiedName) {
                    $catch_inner_list[] = static::phpParserNonValueNodeToAstNode($qualified_name);
                }
                foreach ($n->otherQualifiedNameList as $other_qualified_name) {
                    if ($other_qualified_name instanceof PhpParser\Node\QualifiedName) {
                        $catch_inner_list[] = static::phpParserNonValueNodeToAstNode($other_qualified_name);
                    }
                }
                $catch_list_node = new ast\Node(ast\AST_NAME_LIST, 0, $catch_inner_list, $catch_inner_list[0]->lineno ?? $start_line);
                // TODO: Change to handle multiple exception types in catch clauses
                // after https://github.com/Microsoft/tolerant-php-parser/issues/103 is supported
                return static::astStmtCatch(
                    $catch_list_node,
                    static::variableTokenToString($n->variableName),
                    static::phpParserStmtlistToAstNode($n->compoundStatement, $start_line, true),
                    $start_line
                );
            },
            'Microsoft\PhpParser\Node\Statement\InterfaceDeclaration' => function (PhpParser\Node\Statement\InterfaceDeclaration $n, int $start_line) : ast\Node {
                $end_line = static::getEndLine($n) ?: $start_line;
                return static::astStmtClass(
                    ast\flags\CLASS_INTERFACE,
                    static::tokenToString($n->name),
                    static::interfaceBaseClauseToNode($n->interfaceBaseClause),
                    null,
                    static::phpParserStmtlistToAstNode($n->interfaceMembers->interfaceMemberDeclarations ?? [], $start_line, false),
                    $start_line,
                    $end_line,
                    $n->getDocCommentText()
                );
            },
            'Microsoft\PhpParser\Node\Statement\ClassDeclaration' => function (PhpParser\Node\Statement\ClassDeclaration $n, int $start_line) : ast\Node {
                $end_line = static::getEndLine($n);
                $base_class = $n->classBaseClause->baseClass ?? null;
                return static::astStmtClass(
                    static::phpParserClassModifierToAstClassFlags($n->abstractOrFinalModifier),
                    static::tokenToString($n->name),
                    $base_class !== null ? static::phpParserNonValueNodeToAstNode($base_class) : null,
                    $n->classInterfaceClause,
                    static::phpParserStmtlistToAstNode($n->classMembers->classMemberDeclarations ?? [], self::getStartLine($n->classMembers), false),
                    $start_line,
                    $end_line,
                    $n->getDocCommentText()
                );
            },
            'Microsoft\PhpParser\Node\Statement\TraitDeclaration' => function (PhpParser\Node\Statement\TraitDeclaration $n, int $start_line) : ast\Node {
                $end_line = static::getEndLine($n) ?: $start_line;
                return static::astStmtClass(
                    ast\flags\CLASS_TRAIT,
                    static::tokenToString($n->name),
                    null,
                    null,
                    static::phpParserStmtlistToAstNode($n->traitMembers->traitMemberDeclarations ?? [], self::getStartLine($n->traitMembers), false),
                    $start_line,
                    $end_line,
                    $n->getDocCommentText()
                );
            },
            'Microsoft\PhpParser\Node\ClassConstDeclaration' => function (PhpParser\Node\ClassConstDeclaration $n, int $start_line) : ast\Node {
                return static::phpParserClassConstToAstNode($n, $start_line);
            },
            /** @return null - A stub that will be removed by the caller. */
            'Microsoft\PhpParser\Node\MissingMemberDeclaration' => function (PhpParser\Node\MissingMemberDeclaration $unused_n, int $unused_start_line) {
                // This node type is generated for something that isn't a function/constant/property. e.g. "public example();"
                return null;
            },
            /**
             * @throws InvalidNodeException
             */
            'Microsoft\PhpParser\Node\MethodDeclaration' => function (PhpParser\Node\MethodDeclaration $n, int $start_line) : ast\Node {
                $statements = $n->compoundStatementOrSemicolon;
                $ast_return_type = static::phpParserTypeToAstNode($n->returnType, static::getEndLine($n->returnType) ?: $start_line);
                if (($ast_return_type->children['name'] ?? null) === '') {
                    $ast_return_type = null;
                }
                $original_method_name = $n->name;
                if (($original_method_name->kind ?? null) === TokenKind::Name) {
                    $method_name = static::tokenToString($original_method_name);
                } else {
                    $method_name = 'placeholder_' . $original_method_name->fullStart;
                }

                if ($n->questionToken !== null && $ast_return_type !== null) {
                    $ast_return_type = new ast\Node(ast\AST_NULLABLE_TYPE, 0, ['type' => $ast_return_type], $start_line);
                }
                return static::newAstDecl(
                    ast\AST_METHOD,
                    static::phpParserVisibilityToAstVisibility($n->modifiers) | ($n->byRefToken !== null ? ast\flags\RETURNS_REF : 0),
                    [
                        'params' => static::phpParserParamsToAstParams($n->parameters, $start_line),
                        'uses' => null,
                        'stmts' => static::phpParserStmtlistToAstNode($statements, self::getStartLine($statements), true),
                        'returnType' => $ast_return_type,
                    ],
                    $start_line,
                    $n->getDocCommentText(),
                    $method_name,
                    static::getEndLine($n),
                    self::nextDeclId()
                );
            },
            'Microsoft\PhpParser\Node\Statement\ConstDeclaration' => function (PhpParser\Node\Statement\ConstDeclaration $n, int $start_line) : ast\Node {
                return static::phpParserConstToAstNode($n, $start_line);
            },
            'Microsoft\PhpParser\Node\Statement\DeclareStatement' => function (PhpParser\Node\Statement\DeclareStatement $n, int $start_line) : ast\Node {
                $doc_comment = $n->getDocCommentText();
                $directive = $n->declareDirective;
                if (!($directive instanceof PhpParser\Node\DeclareDirective)) {
                    throw new AssertionError("Unexpected type for directive");
                }
                return static::astStmtDeclare(
                    static::phpParserDeclareListToAstDeclares($directive, $start_line, $doc_comment),
                    $n->statements !== null ? static::phpParserStmtlistToAstNode($n->statements, $start_line, true) : null,
                    $start_line
                );
            },
            'Microsoft\PhpParser\Node\Statement\DoStatement' => function (PhpParser\Node\Statement\DoStatement $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_DO_WHILE,
                    0,
                    [
                        'stmts' => static::phpParserStmtlistToAstNode($n->statement, $start_line, false),
                        'cond' => static::phpParserNodeToAstNode($n->expression),
                    ],
                    $start_line
                );
            },
            /**
             * @return ast\Node|ast\Node[]
             */
            'Microsoft\PhpParser\Node\Expression\EchoExpression' => function (PhpParser\Node\Expression\EchoExpression $n, int $start_line) {
                $ast_echos = [];
                foreach ($n->expressions->children ?? [] as $expr) {
                    if ($expr instanceof Token && $expr->kind === TokenKind::CommaToken) {
                        continue;
                    }
                    $ast_echos[] = new ast\Node(
                        ast\AST_ECHO,
                        0,
                        ['expr' => static::phpParserNodeToAstNode($expr)],
                        $start_line
                    );
                }
                return \count($ast_echos) === 1 ? $ast_echos[0] : $ast_echos;
            },
            'Microsoft\PhpParser\Node\ForeachKey' => function (PhpParser\Node\ForeachKey $n, int $_) : ast\Node {
                return static::phpParserNodeToAstNode($n->expression);
            },
            'Microsoft\PhpParser\Node\Statement\ForeachStatement' => function (PhpParser\Node\Statement\ForeachStatement $n, int $start_line) : ast\Node {
                $foreach_value = $n->foreachValue;
                $value = static::phpParserNodeToAstNode($foreach_value->expression);
                if ($foreach_value->ampersand) {
                    $value = new ast\Node(
                        ast\AST_REF,
                        0,
                        ['var' => $value],
                        $value->lineno ?? $start_line
                    );
                }
                return new ast\Node(
                    ast\AST_FOREACH,
                    0,
                    [
                        'expr' => static::phpParserNodeToAstNode($n->forEachCollectionName),
                        'value' => $value,
                        'key' => $n->foreachKey !== null ? static::phpParserNodeToAstNode($n->foreachKey) : null,
                        'stmts' => static::phpParserStmtlistToAstNode($n->statements, $start_line, true),
                    ],
                    $start_line
                );
                //return static::phpParserStmtlistToAstNode($n->statements, $start_line);
            },
            'Microsoft\PhpParser\Node\FinallyClause' => function (PhpParser\Node\FinallyClause $n, int $start_line) : ast\Node {
                return static::phpParserStmtlistToAstNode($n->compoundStatement, $start_line, false);
            },
            'Microsoft\PhpParser\Node\Statement\FunctionDeclaration' => function (PhpParser\Node\Statement\FunctionDeclaration $n, int $start_line) : ast\Node {
                $end_line = static::getEndLine($n) ?: $start_line;
                $ast_return_type = static::phpParserTypeToAstNode($n->returnType, static::getEndLine($n->returnType) ?: $start_line);
                if (($ast_return_type->children['name'] ?? null) === '') {
                    $ast_return_type = null;
                }
                if ($n->questionToken !== null && $ast_return_type !== null) {
                    $ast_return_type = new ast\Node(ast\AST_NULLABLE_TYPE, 0, ['type' => $ast_return_type], $start_line);
                }

                return static::astDeclFunction(
                    $n->byRefToken !== null,
                    static::tokenToString($n->name),
                    static::phpParserParamsToAstParams($n->parameters, $start_line),
                    $ast_return_type,
                    static::phpParserStmtlistToAstNode($n->compoundStatementOrSemicolon, self::getStartLine($n->compoundStatementOrSemicolon), false),
                    $start_line,
                    $end_line,
                    $n->getDocCommentText()
                );
            },
            /** @return ast\Node|ast\Node[] */
            'Microsoft\PhpParser\Node\Statement\GlobalDeclaration' => function (PhpParser\Node\Statement\GlobalDeclaration $n, int $start_line) {
                $global_nodes = [];
                foreach ($n->variableNameList->children ?? [] as $var) {
                    if ($var instanceof Token && $var->kind === TokenKind::CommaToken) {
                        continue;
                    }
                    $global_nodes[] = new ast\Node(ast\AST_GLOBAL, 0, ['var' => static::phpParserNodeToAstNode($var)], static::getEndLine($var) ?: $start_line);
                }
                return \count($global_nodes) === 1 ? $global_nodes[0] : $global_nodes;
            },
            'Microsoft\PhpParser\Node\Statement\IfStatementNode' => function (PhpParser\Node\Statement\IfStatementNode $n, int $start_line) : ast\Node {
                return static::phpParserIfStmtToAstIfStmt($n, $start_line);
            },
            /** @return ast\Node|ast\Node[] */
            'Microsoft\PhpParser\Node\Statement\InlineHtml' => function (PhpParser\Node\Statement\InlineHtml $n, int $start_line) {
                $text = $n->text;
                if ($text === null) {
                    return [];  // For the beginning/end of files
                }
                return new ast\Node(
                    ast\AST_ECHO,
                    0,
                    ['expr' => static::tokenToRawString($n->text)],
                    $start_line
                );
            },
            /** @suppress PhanTypeMismatchArgument TODO: Make ForStatement have more accurate docs? */
            'Microsoft\PhpParser\Node\Statement\ForStatement' => function (PhpParser\Node\Statement\ForStatement $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_FOR,
                    0,
                    [
                        'init' => $n->forInitializer !== null ? static::phpParserExprListToExprList($n->forInitializer, $start_line) : null,
                        'cond' => $n->forControl !== null     ? static::phpParserExprListToExprList($n->forControl, $start_line) : null,
                        'loop' => $n->forEndOfLoop !== null   ? static::phpParserExprListToExprList($n->forEndOfLoop, $start_line) : null,
                        'stmts' => static::phpParserStmtlistToAstNode($n->statements, $start_line, true),
                    ],
                    $start_line
                );
            },
            /** @return ast\Node[] */
            'Microsoft\PhpParser\Node\Statement\NamespaceUseDeclaration' => function (PhpParser\Node\Statement\NamespaceUseDeclaration $n, int $start_line) {
                $use_clauses = $n->useClauses;
                $results = [];
                $parser_use_kind = $n->functionOrConst->kind ?? null;
                foreach ($use_clauses->children ?? [] as $use_clause) {
                    if (!($use_clause instanceof PhpParser\Node\NamespaceUseClause)) {
                        continue;
                    }
                    $results[] = static::astStmtUseOrGroupUseFromUseClause($use_clause, $parser_use_kind, $start_line);
                }
                return $results;
            },
            'Microsoft\PhpParser\Node\Statement\NamespaceDefinition' => function (PhpParser\Node\Statement\NamespaceDefinition $n, int $start_line) : ast\Node {
                $stmt = $n->compoundStatementOrSemicolon;
                $name_node = $n->name;
                if ($stmt instanceof PhpParser\Node) {
                    $stmts_start_line = self::getStartLine($stmt);
                    $ast_stmt = static::phpParserStmtlistToAstNode($n->compoundStatementOrSemicolon, $stmts_start_line, true);
                    $start_line = $name_node !== null ? self::getStartLine($name_node) : $stmts_start_line;  // imitate php-ast
                } else {
                    $ast_stmt = null;
                }
                return new ast\Node(
                    ast\AST_NAMESPACE,
                    0,
                    [
                        'name' => $name_node !== null ? static::phpParserNameToString($name_node) : null,
                        'stmts' => $ast_stmt,
                    ],
                    $start_line
                );
            },
            'Microsoft\PhpParser\Node\Statement\EmptyStatement' => function (PhpParser\Node\Statement\EmptyStatement $unused_node, int $unused_start_line) : array {
                // `;;`
                return [];
            },
            'Microsoft\PhpParser\Node\PropertyDeclaration' => function (PhpParser\Node\PropertyDeclaration $n, int $start_line) : ast\Node {
                return static::phpParserPropertyToAstNode($n, $start_line);
            },
            'Microsoft\PhpParser\Node\Statement\ReturnStatement' => function (PhpParser\Node\Statement\ReturnStatement $n, int $start_line) : ast\Node {
                $expr_node = $n->expression !== null ? static::phpParserNodeToAstNode($n->expression) : null;
                return new ast\Node(ast\AST_RETURN, 0, ['expr' => $expr_node], $start_line);
            },
            /** @return ast\Node|ast\Node[] */
            'Microsoft\PhpParser\Node\Statement\FunctionStaticDeclaration' => function (PhpParser\Node\Statement\FunctionStaticDeclaration $n, int $start_line) {
                $static_nodes = [];
                foreach ($n->staticVariableNameList->children ?? [] as $var) {
                    if ($var instanceof Token) {
                        continue;
                    }
                    if (!($var instanceof PhpParser\Node\StaticVariableDeclaration)) {
                        // FIXME error tolerance
                        throw new AssertionError("Expected StaticVariableDeclaration");
                    }

                    $static_nodes[] = new ast\Node(ast\AST_STATIC, 0, [
                        'var' => new ast\Node(ast\AST_VAR, 0, ['name' => static::phpParserNodeToAstNode($var->variableName)], static::getEndLine($var) ?: $start_line),
                        'default' => $var->assignment !== null ? static::phpParserNodeToAstNode($var->assignment) : null,
                    ], static::getEndLine($var) ?: $start_line);
                }
                return \count($static_nodes) === 1 ? $static_nodes[0] : $static_nodes;
            },
            'Microsoft\PhpParser\Node\Statement\SwitchStatementNode' => function (PhpParser\Node\Statement\SwitchStatementNode $n, int $start_line) : ast\Node {
                return static::phpParserSwitchListToAstSwitch($n, $start_line);
            },
            'Microsoft\PhpParser\Node\Statement\ThrowStatement' => function (PhpParser\Node\Statement\ThrowStatement $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_THROW,
                    0,
                    ['expr' => static::phpParserNodeToAstNode($n->expression)],
                    $start_line
                );
            },

            'Microsoft\PhpParser\Node\TraitUseClause' => function (PhpParser\Node\TraitUseClause $n, int $start_line) : ast\Node {
                $clauses_list_node = $n->traitSelectAndAliasClauses;
                if ($clauses_list_node instanceof PhpParser\Node\DelimitedList\TraitSelectOrAliasClauseList) {
                    $adaptations_inner = [];
                    foreach ($clauses_list_node->children as $select_or_alias_clause) {
                        if ($select_or_alias_clause instanceof Token) {
                            continue;
                        }
                        if (!($select_or_alias_clause instanceof PhpParser\Node\TraitSelectOrAliasClause)) {
                            throw new AssertionError("Expected TraitSelectOrAliasClause");
                        }
                        $result = static::phpParserNodeToAstNode($select_or_alias_clause);
                        if ($result instanceof ast\Node) {
                            $adaptations_inner[] = $result;
                        }
                    }
                    $adaptations = new ast\Node(ast\AST_TRAIT_ADAPTATIONS, 0, $adaptations_inner, $adaptations_inner[0]->lineno ?? $start_line);
                } else {
                    $adaptations = null;
                }
                return new ast\Node(
                    ast\AST_USE_TRAIT,
                    0,
                    [
                        'traits' => static::phpParserNameListToAstNameList($n->traitNameList->children ?? [], $start_line),
                        'adaptations' => $adaptations,
                    ],
                    $start_line
                );
            },

            /**
             * @return ?ast\Node
             */
            'Microsoft\PhpParser\Node\TraitSelectOrAliasClause' => function (PhpParser\Node\TraitSelectOrAliasClause $n, int $start_line) {
                // FIXME targetName phpdoc is wrong.
                $name = $n->name;
                if ($n->asOrInsteadOfKeyword->kind === TokenKind::InsteadOfKeyword) {
                    $member_name_list = $name->memberName ?? null;
                    if ($member_name_list === null) {
                        return null;
                    }

                    $target_name_list = array_merge([$n->targetName], $n->remainingTargetNames);
                    if (\is_object($member_name_list)) {
                        $member_name_list = [$member_name_list];
                    }
                    // Trait::y insteadof OtherTrait
                    $trait_node = static::phpParserNonValueNodeToAstNode($name->scopeResolutionQualifier);
                    $method_node = static::phpParserNameListToAstNameList($member_name_list, $start_line);
                    $target_node = static::phpParserNameListToAstNameList($target_name_list, $start_line);
                    $outer_method_node = new ast\Node(ast\AST_METHOD_REFERENCE, 0, [
                        'class' => $trait_node,
                        'method' => $method_node->children[0]
                    ], $start_line);

                    if (\count($member_name_list) !== 1) {
                        throw new AssertionError("Expected insteadof member_name_list length to be 1");
                    }
                    $children = [
                        'method' => $outer_method_node,
                        'insteadof' => $target_node,
                    ];
                    return new ast\Node(ast\AST_TRAIT_PRECEDENCE, 0, $children, $start_line);
                } else {
                    if ($name instanceof PhpParser\Node\Expression\ScopedPropertyAccessExpression) {
                        $class_node = static::phpParserNonValueNodeToAstNode($name->scopeResolutionQualifier);
                        $method_node = static::phpParserNodeToAstNode($name->memberName);
                    } else {
                        $class_node = null;
                        $method_node = static::phpParserNameToString($name);
                    }
                    $flags = static::phpParserVisibilityToAstVisibility($n->modifiers, false);
                    $target_name = $n->targetName;
                    $target_name = $target_name instanceof PhpParser\Node\QualifiedName ? static::phpParserNameToString($target_name) : null;
                    $children = [
                        'method' => new ast\Node(ast\AST_METHOD_REFERENCE, 0, [
                            'class' => $class_node,
                            'method' => $method_node,
                        ], $start_line),
                        'alias' => $target_name,
                    ];

                    return new ast\Node(ast\AST_TRAIT_ALIAS, $flags, $children, $start_line);
                }
            },
            'Microsoft\PhpParser\Node\Statement\TryStatement' => function (PhpParser\Node\Statement\TryStatement $n, int $start_line) : ast\Node {
                return static::astNodeTry(
                    static::phpParserStmtlistToAstNode($n->compoundStatement, $start_line, false), // $n->try
                    static::phpParserCatchlistToAstCatchlist($n->catchClauses, $start_line),
                    isset($n->finallyClause) ? static::phpParserStmtlistToAstNode($n->finallyClause->compoundStatement, self::getStartLine($n->finallyClause), false) : null,
                    $start_line
                );
            },
            /** @return ast\Node|ast\Node[] */
            'Microsoft\PhpParser\Node\Expression\UnsetIntrinsicExpression' => function (PhpParser\Node\Expression\UnsetIntrinsicExpression $n, int $start_line) {
                $stmts = [];
                foreach ($n->expressions->children ?? [] as $var) {
                    if ($var instanceof Token) {
                        // Skip over ',' and invalid tokens
                        continue;
                    }
                    $stmts[] = new ast\Node(ast\AST_UNSET, 0, ['var' => static::phpParserNodeToAstNode($var)], static::getEndLine($var) ?: $start_line);
                }
                return \count($stmts) === 1 ? $stmts[0] : $stmts;
            },
            'Microsoft\PhpParser\Node\Statement\WhileStatement' => function (PhpParser\Node\Statement\WhileStatement $n, int $start_line) : ast\Node {
                return static::astNodeWhile(
                    static::phpParserNodeToAstNode($n->expression),
                    static::phpParserStmtlistToAstNode($n->statements, $start_line, true),
                    $start_line
                );
            },
            'Microsoft\PhpParser\Node\Statement\GotoStatement' => function (PhpParser\Node\Statement\GotoStatement $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_GOTO, 0, ['label' => static::tokenToString($n->name)], $start_line);
            },
            /** @return ast\Node[] */
            'Microsoft\PhpParser\Node\Statement\NamedLabelStatement' => function (PhpParser\Node\Statement\NamedLabelStatement $n, int $start_line) {
                $label = new ast\Node(ast\AST_LABEL, 0, ['name' => static::tokenToString($n->name)], $start_line);
                $statement = static::phpParserNodeToAstNode($n->statement);
                return [$label, $statement];
            },
        ];

        foreach ($closures as $key => $_) {
            if (!(\class_exists($key))) {
                throw new AssertionError("Class $key should exist");
            }
        }
        return $closures;
    }

    /**
     * Overridden in TolerantASTConverterWithNodeMapping
     *
     * @param PhpParser\Node\NamespaceUseClause $use_clause
     * @param ?int $parser_use_kind
     * @param int $start_line
     * @return ast\Node
     */
    protected static function astStmtUseOrGroupUseFromUseClause(PhpParser\Node\NamespaceUseClause $use_clause, $parser_use_kind, int $start_line) : ast\Node
    {
        $namespace_name = \rtrim(static::phpParserNameToString($use_clause->namespaceName), '\\');
        if ($use_clause->groupClauses !== null) {
            return static::astStmtGroupUse(
                $parser_use_kind,  // E.g. kind is FunctionKeyword or ConstKeyword or null
                $namespace_name,
                static::phpParserNamespaceUseListToAstUseList($use_clause->groupClauses->children ?? []),
                $start_line
            );
        } else {
            $alias_token = $use_clause->namespaceAliasingClause->name ?? null;
            $alias = $alias_token !== null ? static::tokenToString($alias_token) : null;
            return static::astStmtUse($parser_use_kind, $namespace_name, $alias, $start_line);
        }
    }

    private static function astNodeTry(
        $try_node,
        $catches_node,
        $finally_node,
        int $start_line
    ) : ast\Node {
        // Return fields of $node->children in the same order as php-ast
        $children = [
            'try' => $try_node,
        ];
        if ($catches_node !== null) {
            $children['catches'] = $catches_node;
        }
        $children['finally'] = $finally_node;
        return new ast\Node(ast\AST_TRY, 0, $children, $start_line);
    }

    private static function astStmtCatch(ast\Node $types, string $var, $stmts, int $lineno) : ast\Node
    {
        return new ast\Node(
            ast\AST_CATCH,
            0,
            [
                'class' => $types,
                'var' => new ast\Node(ast\AST_VAR, 0, ['name' => $var], $lineno),
                'stmts' => $stmts,
            ],
            $lineno
        );
    }

    private static function phpParserCatchlistToAstCatchlist(array $catches, int $lineno) : ast\Node
    {
        $children = [];
        foreach ($catches as $parser_catch) {
            $children[] = static::phpParserNonValueNodeToAstNode($parser_catch);
        }
        return new ast\Node(ast\AST_CATCH_LIST, 0, $children, $children[0]->lineno ?? $lineno);
    }

    private static function phpParserNameListToAstNameList(array $types, int $line) : ast\Node
    {
        $ast_types = [];
        foreach ($types as $type) {
            if ($type instanceof Token && $type->kind === TokenKind::CommaToken) {
                continue;
            }
            $ast_types[] = static::phpParserNonValueNodeToAstNode($type);
        }
        return new ast\Node(ast\AST_NAME_LIST, 0, $ast_types, $line);
    }

    private static function astNodeWhile($cond, $stmts, int $start_line) : ast\Node
    {
        return new ast\Node(
            ast\AST_WHILE,
            0,
            [
                'cond' => $cond,
                'stmts' => $stmts,
            ],
            $start_line
        );
    }

    private static function astNodeAssign($var, $expr, int $line, bool $ref) : ast\Node
    {
        return new ast\Node(
            $ref ? ast\AST_ASSIGN_REF : ast\AST_ASSIGN,
            0,
            [
                'var'  => $var,
                'expr' => $expr,
            ],
            $line
        );
    }

    private static function astNodeUnaryOp(int $flags, $expr, int $line) : ast\Node
    {
        return new ast\Node(ast\AST_UNARY_OP, $flags, ['expr' => $expr], $line);
    }

    private static function astNodeCast(int $flags, PhpParser\Node\Expression\CastExpression $n, int $line) : ast\Node
    {
        return new ast\Node(ast\AST_CAST, $flags, ['expr' => static::phpParserNodeToAstNode($n->operand)], static::getEndLine($n) ?: $line);
    }

    private static function astNodeEval($expr, int $line) : ast\Node
    {
        return new ast\Node(ast\AST_INCLUDE_OR_EVAL, ast\flags\EXEC_EVAL, ['expr' => $expr], $line);
    }

    /**
     * @throws Error if the kind could not be found
     */
    private static function phpParserIncludeTokenToAstIncludeFlags(Token $type) : int
    {
        switch ($type->kind) {
            case TokenKind::IncludeKeyword:
                return ast\flags\EXEC_INCLUDE;
            case TokenKind::IncludeOnceKeyword:
                return ast\flags\EXEC_INCLUDE_ONCE;
            case TokenKind::RequireKeyword:
                return ast\flags\EXEC_REQUIRE;
            case TokenKind::RequireOnceKeyword:
                return ast\flags\EXEC_REQUIRE_ONCE;
            default:
                throw new \Error("Unrecognized PhpParser include/require type");
        }
    }
    private static function astNodeInclude($expr, int $line, Token $type) : ast\Node
    {
        $flags = static::phpParserIncludeTokenToAstIncludeFlags($type);
        return new ast\Node(ast\AST_INCLUDE_OR_EVAL, $flags, ['expr' => $expr], $line);
    }

    /**
     * @param PhpParser\Node\QualifiedName|Token|null $type
     * @return ?ast\Node
     */
    protected static function phpParserTypeToAstNode($type, int $line)
    {
        if (\is_null($type)) {
            return null;
        }
        $original_type = $type;
        if ($type instanceof PhpParser\Node\QualifiedName) {
            $type = static::phpParserNameToString($type);
        } elseif ($type instanceof Token) {
            $type = static::tokenToString($type);
        }
        if (\is_string($type)) {
            switch (\strtolower($type)) {
                case 'null':
                    $flags = ast\flags\TYPE_NULL;
                    break;
                case 'bool':
                    $flags = ast\flags\TYPE_BOOL;
                    break;
                case 'int':
                    $flags = ast\flags\TYPE_LONG;
                    break;
                case 'float':
                    $flags = ast\flags\TYPE_DOUBLE;
                    break;
                case 'string':
                    $flags = ast\flags\TYPE_STRING;
                    break;
                case 'array':
                    $flags = ast\flags\TYPE_ARRAY;
                    break;
                case 'object':
                    $flags = ast\flags\TYPE_OBJECT;
                    break;
                case 'callable':
                    $flags = ast\flags\TYPE_CALLABLE;
                    break;
                case 'void':
                    $flags = ast\flags\TYPE_VOID;
                    break;
                case 'iterable':
                    $flags = ast\flags\TYPE_ITERABLE;
                    break;
                default:
                    // TODO: Refactor this into a function accepting a QualifiedName
                    if ($original_type instanceof PhpParser\Node\QualifiedName) {
                        if ($original_type->globalSpecifier !== null) {
                            $ast_kind = ast\flags\NAME_FQ;
                        } elseif (($original_type->relativeSpecifier->namespaceKeyword ?? null) !== null) {
                            $ast_kind = ast\flags\NAME_RELATIVE;
                        } else {
                            $ast_kind = ast\flags\NAME_NOT_FQ;
                        }
                    } else {
                        $ast_kind = ast\flags\NAME_NOT_FQ;
                    }
                    return new ast\Node(
                        ast\AST_NAME,
                        $ast_kind,
                        ['name' => $type],
                        $line
                    );
            }
            return new ast\Node(ast\AST_TYPE, $flags, [], $line);
        }
        return static::phpParserNodeToAstNode($type);
    }

    /**
     * @param bool $by_ref
     * @param ?ast\Node $type
     * @param string $name
     */
    private static function astNodeParam(bool $is_nullable, bool $by_ref, bool $variadic, $type, $name, $default, int $line) : ast\Node
    {
        if ($is_nullable) {
            $type = new ast\Node(
                ast\AST_NULLABLE_TYPE,
                0,
                ['type' => $type],
                $line
            );
        }
        return new ast\Node(
            ast\AST_PARAM,
            ($by_ref ? ast\flags\PARAM_REF : 0) | ($variadic ? ast\flags\PARAM_VARIADIC : 0),
            [
                'type' => $type,
                'name' => $name,
                'default' => $default,
            ],
            $line
        );
    }

    /** @param ?PhpParser\Node\DelimitedList\ParameterDeclarationList $parser_params */
    private static function phpParserParamsToAstParams($parser_params, int $line) : ast\Node
    {
        $new_params = [];
        foreach ($parser_params->children ?? [] as $parser_node) {
            if ($parser_node instanceof Token) {
                continue;
            }
            $new_params[] = static::phpParserNodeToAstNode($parser_node);
        }
        return new ast\Node(
            ast\AST_PARAM_LIST,
            0,
            $new_params,
            $new_params[0]->lineno ?? $line
        );
    }

    protected static function astStub($parser_node) : ast\Node
    {
        // Debugging code.
        if (\getenv(self::ENV_AST_THROW_INVALID)) {
            // @phan-suppress-next-line PhanThrowTypeAbsent only throws for debugging
            throw new \Error("TODO:" . get_class($parser_node));
        }

        $node = new ast\Node();
        // @phan-suppress-next-line PhanTypeMismatchProperty, UnusedPluginSuppression - Deliberately setting $node->kind to a string instead of an integer.
        $node->kind = "TODO:" . get_class($parser_node);
        $node->flags = 0;
        $node->lineno = self::getStartLine($parser_node);
        $node->children = [];
        return $node;
    }

    /**
     * @param ?PhpParser\Node\DelimitedList\UseVariableNameList $uses
     * @param int $line
     * @return ?ast\Node
     */
    private static function phpParserClosureUsesToAstClosureUses(
        $uses,
        int $line
    ) {
        if (count($uses->children ?? []) === 0) {
            return null;
        }
        $ast_uses = [];
        foreach ($uses->children as $use) {
            if ($use instanceof Token) {
                continue;
            }
            if (!($use instanceof PhpParser\Node\UseVariableName)) {
                throw new AssertionError("Expected UseVariableName");
            }
            $ast_uses[] = new ast\Node(ast\AST_CLOSURE_VAR, $use->byRef ? 1 : 0, ['name' => static::tokenToString($use->variableName)], self::getStartLine($use));
        }
        return new ast\Node(ast\AST_CLOSURE_USES, 0, $ast_uses, $ast_uses[0]->lineno ?? $line);
    }

    /**
     * @return ?string
     */
    private static function resolveDocCommentForClosure(PhpParser\Node\Expression\AnonymousFunctionCreationExpression $node)
    {
        $doc_comment = $node->getDocCommentText();
        if ($doc_comment) {
            return $doc_comment;
        }
        for ($prev_node = $node; $node = $node->parent; $prev_node = $node) {
            if ($node instanceof PhpParser\Node\Expression\AssignmentExpression ||
                $node instanceof PhpParser\Node\Expression\ParenthesizedExpression ||
                $node instanceof PhpParser\Node\ArrayElement ||
                $node instanceof PhpParser\Node\Statement\ReturnStatement) {
                $doc_comment = $node->getDocCommentText();
                if ($doc_comment) {
                    return $doc_comment;
                }
                continue;
            }
            if ($node instanceof PhpParser\Node\Expression\ArgumentExpression) {
                // Skip ArgumentExpression and the PhpParser\Node\DelimitedList\ArgumentExpressionList
                // to get to the CallExpression
                $node = $node->parent->parent;
                // fall through
            }
            if ($node instanceof PhpParser\Node\Expression\MemberAccessExpression) {
                // E.g. ((Closure)->bindTo())
                if ($prev_node !== $node->dereferencableExpression) {
                    return null;
                }
                $doc_comment = $node->getDocCommentText();
                if ($doc_comment) {
                    return $doc_comment;
                }
                continue;
            }
            if ($node instanceof PhpParser\Node\Expression\CallExpression) {
                if ($prev_node === $node->callableExpression) {
                    $doc_comment = $node->getDocCommentText();
                    if ($doc_comment) {
                        return $doc_comment;
                    }
                    continue;
                }
                if ($node->callableExpression instanceof PhpParser\Node\Expression\AnonymousFunctionCreationExpression) {
                    return null;
                }
                $found = false;
                foreach ($node->argumentExpressionList->children ?? [] as $argument_expression) {
                    if (!($argument_expression instanceof PhpParser\Node\Expression\ArgumentExpression)) {
                        continue;
                    }
                    $expression = $argument_expression->expression;
                    if ($expression === $prev_node) {
                        $found = true;
                        $doc_comment = $node->getDocCommentText();
                        if ($doc_comment) {
                            return $doc_comment;
                        }
                        break;
                    }
                    if (!($expression instanceof PhpParser\Node)) {
                        continue;
                    }
                    if ($expression instanceof PhpParser\Node\ConstElement || $expression instanceof PhpParser\Node\NumericLiteral || $expression instanceof PhpParser\Node\StringLiteral) {
                        continue;
                    }
                    return null;
                }

                if ($found) {
                    continue;
                }
            }
            break;
        }
        return null;
    }

    /**
     * @param ?string $doc_comment
     */
    private static function astDeclClosure(
        bool $by_ref,
        bool $static,
        ast\Node $params,
        $uses,
        $stmts,
        $return_type,
        int $start_line,
        int $end_line,
        $doc_comment
    ) : ast\Node {
        return static::newAstDecl(
            ast\AST_CLOSURE,
            ($by_ref ? ast\flags\RETURNS_REF : 0) | ($static ? ast\flags\MODIFIER_STATIC : 0),
            [
                'params' => $params,
                'uses' => $uses,
                'stmts' => $stmts,
                'returnType' => $return_type,
            ],
            $start_line,
            $doc_comment,
            '{closure}',
            $end_line,
            self::nextDeclId()
        );
    }

    /**
     * @param ?ast\Node $return_type
     * @param ?ast\Node $stmts (TODO: create empty statement list instead of null)
     * @param ?string $doc_comment
     */
    private static function astDeclFunction(
        bool $by_ref,
        string $name,
        ast\Node $params,
        $return_type,
        $stmts,
        int $line,
        int $end_line,
        $doc_comment
    ) : ast\Node {
        return static::newAstDecl(
            ast\AST_FUNC_DECL,
            $by_ref ? ast\flags\RETURNS_REF : 0,
            [
                'params' => $params,
                'uses' => null,
                'stmts' => $stmts,
                'returnType' => $return_type,
            ],
            $line,
            $doc_comment,
            $name,
            $end_line,
            self::nextDeclId()
        );
    }

    /**
     * @param ?Token $flags
     * @throws InvalidArgumentException if the class flags were unexpected
     */
    private static function phpParserClassModifierToAstClassFlags($flags) : int
    {
        if ($flags === null) {
            return 0;
        }
        switch ($flags->kind) {
            case TokenKind::AbstractKeyword:
                return ast\flags\CLASS_ABSTRACT;
            case TokenKind::FinalKeyword:
                return ast\flags\CLASS_FINAL;
            default:
                throw new InvalidArgumentException("Unexpected kind '" . Token::getTokenKindNameFromValue($flags->kind) . "'");
        }
    }

    /**
     * @param ?PhpParser\Node\InterfaceBaseClause $node
     * @return ?ast\Node
     */
    private static function interfaceBaseClauseToNode($node)
    {
        if (!$node instanceof PhpParser\Node\InterfaceBaseClause) {
            // TODO: real placeholder?
            return null;
        }

        $interface_extends_name_list = [];
        foreach ($node->interfaceNameList->children ?? [] as $implement) {
            if ($implement instanceof Token && $implement->kind === TokenKind::CommaToken) {
                continue;
            }
            $interface_extends_name_list[] = static::phpParserNonValueNodeToAstNode($implement);
        }
        return new ast\Node(ast\AST_NAME_LIST, 0, $interface_extends_name_list, $interface_extends_name_list[0]->lineno);
    }

    /**
     * @param int $flags
     * @param ?string $name
     * @param ?ast\Node $extends
     * @param ?PhpParser\Node\ClassInterfaceClause $implements
     * @param ?ast\Node $stmts
     * @param int $line
     * @param int $end_line
     * @param ?string $doc_comment
     */
    private static function astStmtClass(
        int $flags,
        $name,
        $extends,
        $implements,
        $stmts,
        int $line,
        int $end_line,
        $doc_comment
    ) : ast\Node {

        // NOTE: `null` would be an anonymous class.
        // the empty string or '0' is a missing string we pretend is an anonymous class
        // so that Phan won't throw an UnanalyzableException during the analysis phase
        if ($name === null || !$name) {
            $flags |= ast\flags\CLASS_ANONYMOUS;
        }

        if (($flags & ast\flags\CLASS_INTERFACE) > 0) {
            $children = [
                'extends'    => null,
                'implements' => $extends,
                'stmts'      => $stmts,
            ];
        } else {
            if ($implements !== null) {
                $ast_implements_inner = [];
                foreach ($implements->interfaceNameList->children ?? [] as $implement) {
                    // TODO: simplify?
                    if ($implement instanceof Token && $implement->kind === TokenKind::CommaToken) {
                        continue;
                    }
                    $ast_implements_inner[] = static::phpParserNonValueNodeToAstNode($implement);
                }
                if (\count($ast_implements_inner) > 0) {
                    $ast_implements = new ast\Node(ast\AST_NAME_LIST, 0, $ast_implements_inner, $ast_implements_inner[0]->lineno);
                } else {
                    $ast_implements = null;
                }
            } else {
                $ast_implements = null;
            }
            $children = [
                'extends'    => $extends,
                'implements' => $ast_implements,
                'stmts'      => $stmts,
            ];
        }

        return static::newAstDecl(
            ast\AST_CLASS,
            $flags,
            $children,
            $line,
            $doc_comment,
            $name,
            $end_line,
            self::nextDeclId()
        );
    }

    /**
     * @param ?PhpParser\Node\DelimitedList\ArgumentExpressionList $args
     */
    private static function phpParserArgListToAstArgList($args, int $line) : ast\Node
    {
        $ast_args = [];
        foreach ($args->children ?? [] as $arg) {
            if ($arg instanceof Token && $arg->kind === TokenKind::CommaToken) {
                continue;
            }
            $ast_args[] = static::phpParserNodeToAstNode($arg);
        }
        return new ast\Node(ast\AST_ARG_LIST, 0, $ast_args, $args ? self::getStartLine($args) : $line);
    }

    /**
     * @param ?int $kind
     * @throws InvalidArgumentException if the token kind was somehow invalid
     */
    private static function phpParserNamespaceUseKindToASTUseFlags($kind) : int
    {
        switch ($kind ?? 0) {
            case TokenKind::FunctionKeyword:
                return ast\flags\USE_FUNCTION;
            case TokenKind::ConstKeyword:
                return ast\flags\USE_CONST;
            case 0:
                return ast\flags\USE_NORMAL;
            default:
                throw new \InvalidArgumentException("Unexpected kind '" . Token::getTokenKindNameFromValue($kind ?? 0) . "'");
        }
    }

    /**
     * @param Token[]|PhpParser\Node\NamespaceUseGroupClause[]|PhpParser\Node[] $uses
     * @return ast\Node[]
     */
    private static function phpParserNamespaceUseListToAstUseList(array $uses) : array
    {
        $ast_uses = [];
        foreach ($uses as $use_clause) {
            if (!($use_clause instanceof PhpParser\Node\NamespaceUseGroupClause)) {
                continue;
            }
            // ast doesn't fill in an alias if it's identical to the real name,
            // but phpParser does?
            $namespace_name = \rtrim(static::phpParserNameToString($use_clause->namespaceName), '\\');
            $alias_token = $use_clause->namespaceAliasingClause->name ?? null;
            $alias = $alias_token !== null ? static::tokenToString($alias_token) : null;

            $ast_uses[] = new ast\Node(
                ast\AST_USE_ELEM,
                static::phpParserNamespaceUseKindToASTUseFlags($use_clause->functionOrConst->kind ?? 0),
                [
                    'name' => $namespace_name,
                    'alias' => $alias !== $namespace_name ? $alias : null,
                ],
                self::getStartLine($use_clause)
            );
        }
        return $ast_uses;
    }

    /**
     * @param ?string $alias
     * @return ast\Node
     */
    private static function astStmtUse($type, string $name, $alias, int $line) : ast\Node
    {
        $use_inner = new ast\Node(ast\AST_USE_ELEM, 0, ['name' => $name, 'alias' => $alias], $line);
        return new ast\Node(
            ast\AST_USE,
            static::phpParserNamespaceUseKindToASTUseFlags($type),
            [$use_inner],
            $line
        );
    }

    /**
     * @param ?int $type
     * @param ?string $prefix
     */
    private static function astStmtGroupUse($type, $prefix, array $uses, int $line) : ast\Node
    {
        $flags = static::phpParserNamespaceUseKindToASTUseFlags($type);
        $uses = new ast\Node(ast\AST_USE, 0, $uses, $line);
        if ($flags === ast\flags\USE_NORMAL) {
            foreach ($uses->children as $use) {
                if ($use->flags !== 0) {
                    $flags = 0;
                    break;
                }
            }
        } else {
            foreach ($uses->children as $use) {
                if ($use->flags === ast\flags\USE_NORMAL) {
                    $use->flags = 0;
                }
            }
        }

        return new ast\Node(
            ast\AST_GROUP_USE,
            $flags,
            [
                'prefix' => $prefix,
                'uses' => $uses,
            ],
            $line
        );
    }

    private static function astIfElem($cond, $stmts, int $line) : ast\Node
    {
        return new ast\Node(ast\AST_IF_ELEM, 0, ['cond' => $cond, 'stmts' => $stmts], $line);
    }

    private static function phpParserSwitchListToAstSwitch(PhpParser\Node\Statement\SwitchStatementNode $node, int $start_line) : ast\Node
    {
        $stmts = [];
        $node_line = static::getEndLine($node) ?? $start_line;
        foreach ($node->caseStatements as $case) {
            $case_line = static::getEndLine($case);
            $stmts[] = new ast\Node(
                ast\AST_SWITCH_CASE,
                0,
                [
                    'cond' => $case->expression !== null ? static::phpParserNodeToAstNode($case->expression) : null,
                    'stmts' => static::phpParserStmtlistToAstNode($case->statementList, $case_line, false),
                ],
                $case_line ?? $node_line
            );
        }
        return new ast\Node(ast\AST_SWITCH, 0, [
            'cond' => static::phpParserNodeToAstNode($node->expression),
            'stmts' => new ast\Node(ast\AST_SWITCH_LIST, 0, $stmts, $stmts[0]->lineno ?? $node_line),
        ], $node_line);
    }

    /**
     * @param PhpParser\Node[]|PhpParser\Node|Token $stmts
     */
    private static function getStartLineOfStatementOrStatements($stmts) : int
    {
        if (is_array($stmts)) {
            return isset($stmts[0]) ? self::getStartLine($stmts[0]) : 0;
        }
        return self::getStartLine($stmts);
    }

    private static function phpParserIfStmtToAstIfStmt(PhpParser\Node\Statement\IfStatementNode $node, int $start_line) : ast\Node
    {
        $if_elem = static::astIfElem(
            static::phpParserNodeToAstNode($node->expression),
            static::phpParserStmtlistToAstNode(
                $node->statements,
                self::getStartLineOfStatementOrStatements($node->statements) ?: $start_line,
                true
            ),
            $start_line
        );
        $if_elems = [$if_elem];
        foreach ($node->elseIfClauses as $else_if) {
            $if_elem_line = self::getStartLine($else_if);
            $if_elem = static::astIfElem(
                static::phpParserNodeToAstNode($else_if->expression),
                static::phpParserStmtlistToAstNode(
                    $else_if->statements,
                    self::getStartLineOfStatementOrStatements($else_if->statements)
                ),
                $if_elem_line
            );
            $if_elems[] = $if_elem;
        }
        $parser_else_node = $node->elseClause;
        if ($parser_else_node) {
            $parser_else_line = self::getStartLineOfStatementOrStatements($parser_else_node->statements);
            $if_elems[] = static::astIfElem(
                null,
                static::phpParserStmtlistToAstNode($parser_else_node->statements, $parser_else_line),
                $parser_else_line
            );
        }
        return new ast\Node(ast\AST_IF, 0, $if_elems, $start_line);
    }

    /**
     * @return ast\Node|string|int|float
     */
    private static function astNodeBinaryop(int $flags, PhpParser\Node\Expression\BinaryExpression $n, int $start_line)
    {
        try {
            $left_node = static::phpParserNodeToAstNode($n->leftOperand);
        } catch (InvalidNodeException $_) {
            if (self::$should_add_placeholders) {
                $left_node = static::newPlaceholderExpression($n->leftOperand);
            } else {
                // convert `;$b ^;` to `;$b;`
                return static::phpParserNodeToAstNode($n->rightOperand);
            }
        }
        try {
            $right_node = static::phpParserNodeToAstNode($n->rightOperand);
        } catch (InvalidNodeException $_) {
            if (self::$should_add_placeholders) {
                $right_node = static::newPlaceholderExpression($n->rightOperand);
            } else {
                // convert `;^ $b;` to `;$b;`
                return $left_node;
            }
        }

        return new ast\Node(
            ast\AST_BINARY_OP,
            $flags,
            [
                'left' => $left_node,
                'right' => $right_node,
            ],
            $start_line
        );
    }

    // Binary assignment operation such as +=
    private static function astNodeAssignop(int $flags, PhpParser\Node\Expression\BinaryExpression $n, int $start_line) : \ast\Node
    {
        try {
            $var_node = static::phpParserNodeToAstNode($n->leftOperand);
        } catch (InvalidNodeException $_) {
            if (self::$should_add_placeholders) {
                $var_node = new ast\Node(ast\AST_VAR, 0, ['name' => '__INCOMPLETE_VARIABLE__'], $start_line);
            } else {
                // convert `;= $b;` to `;$b;`
                return static::phpParserNodeToAstNode($n->rightOperand);
            }
        }
        $expr_node = static::phpParserNodeToAstNode($n->rightOperand);
        return new ast\Node(
            ast\AST_ASSIGN_OP,
            $flags,
            [
                'var' => $var_node,
                'expr' => $expr_node,
            ],
            $start_line
        );
    }

    /**
     * @param PhpParser\Node\Expression\AssignmentExpression|PhpParser\Node\Expression\Variable $n
     * @param ?string $doc_comment
     * @throws InvalidNodeException if the type can't be converted to a valid AST
     * @throws InvalidArgumentException if the passed in class is completely unexpected
     */
    private static function phpParserPropelemToAstPropelem($n, $doc_comment) : ast\Node
    {
        if ($n instanceof PhpParser\Node\Expression\AssignmentExpression) {
            $name_node = $n->leftOperand;
            if (!($name_node instanceof PhpParser\Node\Expression\Variable)) {
                throw new InvalidNodeException();
            }
            $children = [
                'name' => static::phpParserNodeToAstNode($name_node->name),
                'default' => $n->rightOperand ? static::phpParserNodeToAstNode($n->rightOperand) : null,
            ];
        } elseif ($n instanceof PhpParser\Node\Expression\Variable) {
            $name = $n->name;
            if (!($name instanceof Token) || !$name->length) {
                throw new InvalidNodeException();
            }
            $children = [
                'name' => static::tokenToString($name),
                'default' => null,
            ];
        } else {
            throw new \InvalidArgumentException("Unexpected class for property element: Expected Variable or AssignmentExpression, got: " . static::debugDumpNodeOrToken($n));
        }

        $start_line = self::getStartLine($n);

        $children['docComment'] = static::extractPhpdocComment($n) ?? $doc_comment;
        return new ast\Node(ast\AST_PROP_ELEM, 0, $children, $start_line);
    }

    /**
     * @param ?string $doc_comment
     */
    private static function phpParserConstelemToAstConstelem(PhpParser\Node\ConstElement $n, $doc_comment) : ast\Node
    {
        $start_line = self::getStartLine($n);
        $children = [
            'name' => static::variableTokenToString($n->name),
            'value' => static::phpParserNodeToAstNode($n->assignment),
        ];

        if (self::$php_version_id_parsing >= 70100 || self::$parse_all_doc_comments) {
            $children['docComment'] = static::extractPhpdocComment($n) ?? $doc_comment;
        }
        return new ast\Node(ast\AST_CONST_ELEM, 0, $children, $start_line);
    }

    /**
     * @param Token[] $visibility
     * @throws RuntimeException if a visibility token was unexpected
     */
    private static function phpParserVisibilityToAstVisibility(array $visibility, bool $automatically_add_public = true) : int
    {
        $ast_visibility = 0;
        foreach ($visibility as $token) {
            switch ($token->kind) {
                case TokenKind::PublicKeyword:
                    $ast_visibility |= ast\flags\MODIFIER_PUBLIC;
                    break;
                case TokenKind::ProtectedKeyword:
                    $ast_visibility |= ast\flags\MODIFIER_PROTECTED;
                    break;
                case TokenKind::PrivateKeyword:
                    $ast_visibility |= ast\flags\MODIFIER_PRIVATE;
                    break;
                case TokenKind::StaticKeyword:
                    $ast_visibility |= ast\flags\MODIFIER_STATIC;
                    break;
                case TokenKind::AbstractKeyword:
                    $ast_visibility |= ast\flags\MODIFIER_ABSTRACT;
                    break;
                case TokenKind::FinalKeyword:
                    $ast_visibility |= ast\flags\MODIFIER_FINAL;
                    break;
                default:
                    throw new \RuntimeException("Unexpected visibility modifier '" . Token::getTokenKindNameFromValue($token->kind) . "'");
            }
        }
        if ($automatically_add_public && !($ast_visibility & (ast\flags\MODIFIER_PUBLIC | ast\flags\MODIFIER_PROTECTED | ast\flags\MODIFIER_PRIVATE))) {
            $ast_visibility |= ast\flags\MODIFIER_PUBLIC;
        }
        return $ast_visibility;
    }

    private static function phpParserPropertyToAstNode(PhpParser\Node\PropertyDeclaration $n, int $start_line) : ast\Node
    {
        $prop_elems = [];
        $doc_comment = $n->getDocCommentText();

        foreach ($n->propertyElements->children ?? [] as $i => $prop) {
            if ($prop instanceof Token) {
                continue;
            }
            // @phan-suppress-next-line PhanTypeMismatchArgument casting to a more specific node
            $prop_elems[] = static::phpParserPropelemToAstPropelem($prop, $i === 0 ? $doc_comment : null);
        }
        $flags = static::phpParserVisibilityToAstVisibility($n->modifiers, false);

        return new ast\Node(ast\AST_PROP_DECL, $flags, $prop_elems, $prop_elems[0]->lineno ?? (self::getStartLine($n) ?: $start_line));
    }

    private static function phpParserClassConstToAstNode(PhpParser\Node\ClassConstDeclaration $n, int $start_line) : ast\Node
    {
        $const_elems = [];
        $doc_comment = $n->getDocCommentText();
        foreach ($n->constElements->children ?? [] as $i => $const_elem) {
            if ($const_elem instanceof Token) {
                continue;
            }
            // @phan-suppress-next-line PhanTypeMismatchArgument casting to something more specific
            $const_elems[] = static::phpParserConstelemToAstConstelem($const_elem, $i === 0 ? $doc_comment : null);
        }
        $flags = static::phpParserVisibilityToAstVisibility($n->modifiers);

        return new ast\Node(ast\AST_CLASS_CONST_DECL, $flags, $const_elems, $const_elems[0]->lineno ?? $start_line);
    }

    /**
     * @throws InvalidNodeException
     */
    private static function phpParserConstToAstNode(PhpParser\Node\Statement\ConstDeclaration $n, int $start_line) : ast\Node
    {
        $const_elems = [];
        $doc_comment = $n->getDocCommentText();
        foreach ($n->constElements->children ?? [] as $i => $prop) {
            if ($prop instanceof Token) {
                continue;
            }
            if (!($prop instanceof PhpParser\Node\ConstElement)) {
                throw new InvalidNodeException();
            }
            $const_elems[] = static::phpParserConstelemToAstConstelem($prop, $i === 0 ? $doc_comment : null);
        }

        return new ast\Node(ast\AST_CONST_DECL, 0, $const_elems, $const_elems[0]->lineno ?? $start_line);
    }

    /**
     * @param ?string $first_doc_comment
     */
    private static function phpParserDeclareListToAstDeclares(PhpParser\Node\DeclareDirective $declare, int $start_line, $first_doc_comment) : ast\Node
    {
        $ast_declare_elements = [];
        if ($declare->name->length > 0 && $declare->literal->length > 0) {
            // Skip SkippedToken or MissingToken
            $children = [
                'name' => static::tokenToString($declare->name),
                'value' => static::tokenToScalar($declare->literal),
            ];
            $doc_comment = static::extractPhpdocComment($declare) ?? $first_doc_comment;
            // $first_doc_comment = null;
            if (self::$php_version_id_parsing >= 70100 || self::$parse_all_doc_comments) {
                $children['docComment'] = $doc_comment;
            }
            $node = new ast\Node(ast\AST_CONST_ELEM, 0, $children, self::getStartLine($declare));
            $ast_declare_elements[] = $node;
        }
        return new ast\Node(ast\AST_CONST_DECL, 0, $ast_declare_elements, $start_line);
    }

    /**
     * @param ?ast\Node $stmts
     */
    private static function astStmtDeclare(ast\Node $declares, $stmts, int $start_line) : ast\Node
    {
        $children = [
            'declares' => $declares,
            'stmts' => $stmts,
        ];
        return new ast\Node(ast\AST_DECLARE, 0, $children, $start_line);
    }

    private static function astNodeCall($expr, $args, int $start_line) : ast\Node
    {
        if (\is_string($expr)) {
            if (substr($expr, 0, 1) === '\\') {
                $expr = substr($expr, 1);
            }
            $expr = new ast\Node(ast\AST_NAME, ast\flags\NAME_FQ, ['name' => $expr], $start_line);
        }
        return new ast\Node(ast\AST_CALL, 0, ['expr' => $expr, 'args' => $args], $start_line);
    }

    /**
     * @param ast\Node $expr
     * @param ast\Node $method
     */
    private static function astNodeMethodCall($expr, $method, ast\Node $args, int $start_line) : ast\Node
    {
        return new ast\Node(ast\AST_METHOD_CALL, 0, ['expr' => $expr, 'method' => $method, 'args' => $args], $start_line);
    }

    /**
     * @param ast\Node|string $class
     * @param ast\Node $method
     */
    private static function astNodeStaticCall($class, $method, ast\Node $args, int $start_line) : ast\Node
    {
        // TODO: is this applicable?
        if (\is_string($class)) {
            if (\substr($class, 0, 1) === '\\') {
                $class = \substr($class, 1);
            }
            $class = new ast\Node(ast\AST_NAME, ast\flags\NAME_FQ, ['name' => $class], $start_line);
        }
        return new ast\Node(ast\AST_STATIC_CALL, 0, ['class' => $class, 'method' => $method, 'args' => $args], $start_line);
    }

    /**
     * TODO: Get rid of this function?
     * @return ?string the doc comment, or null
     */
    private static function extractPhpdocComment($comments)
    {
        if (\is_string($comments)) {
            return $comments;
        }
        if ($comments instanceof PhpParser\Node) {
            // TODO: Extract only the substring with doc comment text?
            return $comments->getDocCommentText() ?: null;
        }
        if ($comments === null) {
            return null;
        }
        if (!(\is_array($comments))) {
            throw new AssertionError("Expected an array of comments");
        }
        if (\count($comments) === 0) {
            return null;
        }
    }

    private static function phpParserListToAstList(PhpParser\Node\Expression\ListIntrinsicExpression $n, int $start_line) : ast\Node
    {
        $ast_items = [];
        $prev_was_element = false;
        foreach ($n->listElements->children ?? [] as $item) {
            if ($item instanceof Token) {
                if (!$prev_was_element) {
                    $ast_items[] = null;
                    continue;
                }
                $prev_was_element = false;
                continue;
            } else {
                $prev_was_element = true;
            }
            if (!($item instanceof PhpParser\Node\ArrayElement)) {
                throw new AssertionError("Expected ArrayElement");
            }
            $ast_items[] = new ast\Node(ast\AST_ARRAY_ELEM, 0, [
                'value' => static::phpParserNodeToAstNode($item->elementValue),
                'key' => $item->elementKey !== null ? static::phpParserNodeToAstNode($item->elementKey) : null,
            ], self::getStartLine($item));
        }
        if (self::$php_version_id_parsing < 70100 && \count($ast_items) === 0) {
            $ast_items[] = null;
        }
        return new ast\Node(ast\AST_ARRAY, ast\flags\ARRAY_SYNTAX_LIST, $ast_items, $start_line);
    }

    private static function phpParserArrayToAstArray(PhpParser\Node\Expression\ArrayCreationExpression $n, int $start_line) : ast\Node
    {
        $ast_items = [];
        $prev_was_element = false;
        foreach ($n->arrayElements->children ?? [] as $item) {
            if ($item instanceof Token) {
                if (!$prev_was_element) {
                    $ast_items[] = null;
                    continue;
                }
                $prev_was_element = false;
                continue;
            } else {
                $prev_was_element = true;
            }
            if (!($item instanceof PhpParser\Node\ArrayElement)) {
                throw new AssertionError("Expected ArrayElement");
            }
            $flags = $item->byRef ? ast\flags\PARAM_REF : 0;
            $ast_items[] = new ast\Node(ast\AST_ARRAY_ELEM, $flags, [
                'value' => static::phpParserNodeToAstNode($item->elementValue),
                'key' => $item->elementKey !== null ? static::phpParserNodeToAstNode($item->elementKey) : null,
            ], self::getStartLine($item));
        }
        if (self::$php_version_id_parsing < 70100) {
            $flags = 0;
        } else {
            $kind = $n->openParenOrBracket->kind;
            if ($kind === TokenKind::OpenBracketToken) {
                $flags = ast\flags\ARRAY_SYNTAX_SHORT;
            } else {
                $flags = ast\flags\ARRAY_SYNTAX_LONG;
            }
        }
        // Workaround for ast line choice
        return new ast\Node(ast\AST_ARRAY, $flags, $ast_items, $ast_items[0]->lineno ?? $start_line);
    }

    /**
     * @return ?ast\Node
     * @throws InvalidNodeException if the member name could not be converted
     *
     * (and various other exceptions)
     */
    private static function phpParserMemberAccessExpressionToAstProp(PhpParser\Node\Expression\MemberAccessExpression $n, int $start_line)
    {
        // TODO: Check for incomplete tokens?
        $member_name = $n->memberName;
        try {
            $name = static::phpParserNodeToAstNode($member_name);  // complex expression
        } catch (InvalidNodeException $e) {
            if (self::$should_add_placeholders) {
                $name = '__INCOMPLETE_PROPERTY__';
            } else {
                throw $e;
            }
        }
        return new ast\Node(ast\AST_PROP, 0, [
            'expr'  => static::phpParserNodeToAstNode($n->dereferencableExpression),
            'prop'  => $name,  // ast\Node|string
        ], $start_line);
    }

    /**
     * @return int|string|float|bool|null
     */
    private static function tokenToScalar(Token $n)
    {
        $str = static::tokenToString($n);
        $int = \filter_var($str, FILTER_VALIDATE_INT);
        if ($int !== false) {
            return $int;
        }
        $float = \filter_var($str, FILTER_VALIDATE_FLOAT);
        if ($float !== false) {
            return $float;
        }

        return StringUtil::parse($str);
    }

    private static function parseQuotedString(PhpParser\Node\StringLiteral $n) : string
    {
        $start = $n->getStart();
        $text = \substr(self::$file_contents, $start, $n->getEndPosition() - $start);
        return StringUtil::parse($text);
    }

    /**
     * @suppress PhanPartialTypeMismatchArgumentInternal hopefully in range
     */
    private static function variableTokenToString(Token $n) : string
    {
        return \ltrim(\trim($n->getText(self::$file_contents)), '$');
    }

    /**
     * @suppress PhanPartialTypeMismatchReturn this is in bounds and $file_contents is a string
     */
    private static function tokenToRawString(Token $n) : string
    {
        return $n->getText(self::$file_contents);
    }

    /** @internal */
    const _MAGIC_CONST_LOOKUP = [
        '__LINE__' => \ast\flags\MAGIC_LINE,
        '__FILE__' => \ast\flags\MAGIC_FILE,
        '__DIR__' => \ast\flags\MAGIC_DIR,
        '__NAMESPACE__' => \ast\flags\MAGIC_NAMESPACE,
        '__FUNCTION__' => \ast\flags\MAGIC_FUNCTION,
        '__METHOD__' => \ast\flags\MAGIC_METHOD,
        '__CLASS__' => \ast\flags\MAGIC_CLASS,
        '__TRAIT__' => \ast\flags\MAGIC_TRAIT,
    ];

    // FIXME don't use in places expecting non-strings.
    /**
     * @phan-suppress PhanPartialTypeMismatchArgumentInternal hopefully in range
     */
    private static function tokenToString(Token $n) : string
    {
        $result = \trim($n->getText(self::$file_contents));
        $kind = $n->kind;
        if ($kind === TokenKind::VariableName) {
            return \trim($result, '$');
        }
        return $result;
    }

    /**
     * @param PhpParser\Node\Expression|PhpParser\Node\QualifiedName|Token $scope_resolution_qualifier
     */
    private static function phpParserClassconstfetchToAstClassconstfetch($scope_resolution_qualifier, string $name, int $start_line) : ast\Node
    {
        return new ast\Node(ast\AST_CLASS_CONST, 0, [
            'class' => static::phpParserNonValueNodeToAstNode($scope_resolution_qualifier),
            'const' => $name,
        ], $start_line);
    }

    /**
     * @return string
     * @throws InvalidNodeException if the qualified type name could not be converted to a valid php-ast type name
     */
    private static function phpParserNameToString(PhpParser\Node\QualifiedName $name) : string
    {
        $name_parts = $name->nameParts;
        // TODO: Handle error case (can there be missing parts?)
        $result = '';
        foreach ($name_parts as $part) {
            $part_as_string = static::tokenToString($part);
            if ($part_as_string !== '') {
                $result .= \trim($part_as_string);
            }
        }
        $result = \rtrim(\preg_replace('/\\\\{2,}/', '\\', $result), '\\');
        if ($result === '') {
            // Would lead to "The name cannot be empty" when parsing
            throw new InvalidNodeException();
        }
        return $result;
    }

    /**
     * NOTE: this may be removed in the future.
     *
     * @return ast\Node
     */
    private static function newAstDecl(int $kind, int $flags, array $children, int $lineno, string $doc_comment = null, string $name = null, int $end_lineno = 0, int $decl_id = -1) : ast\Node
    {
        $decl_children = [];
        $decl_children['name'] = $name;
        $decl_children['docComment'] = $doc_comment;
        $decl_children += $children;
        if ($decl_id >= 0) {
            $decl_children['__declId'] = $decl_id;
        }
        $node = new ast\Node($kind, $flags, $decl_children, $lineno);
        if (\is_int($end_lineno)) {
            $node->endLineno = $end_lineno;
        }
        return $node;
    }

    private static function nextDeclId() : int
    {
        return self::$decl_id++;
    }

    /** @param PhpParser\Node|Token $n the node or token to convert to a placeholder */
    private static function newPlaceholderExpression($n) : ast\Node
    {
        $start_line = self::getStartLine($n);
        $name_node = new ast\Node(ast\AST_NAME, ast\flags\NAME_FQ, ['name' => '__INCOMPLETE_EXPR__'], $start_line);
        return new ast\Node(ast\AST_CONST, 0, ['name' => $name_node], $start_line);
    }
}
class_exists(TolerantASTConverterWithNodeMapping::class);
