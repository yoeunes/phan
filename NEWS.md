Phan NEWS

?? ??? 2018, Phan 1.0.2 (dev)
-----------------------

New features(Analysis)
+ Allow spaces on either side of `|` in union types
  (e.g. `@param array | ArrayAccess $x`)
+ Warn about array destructuring assignments from non-arrays (#1818)
  (E.g. `[$x] = 2`)

  New issue type: `PhanTypeInvalidExpressionArrayDestructuring`
+ Infer the number of groups for $matches in `preg_match()`

  Named subpatterns, non-capturing patterns, and regular expression options are not supported yet.
  Phan will just infer a more generic type such as `string[]` (depending on the bit flags).
+ Warn about ambiguous uses of `Closure():void` in phpdoc.
  Also, make that syntax count as a reference to `use Closure;` in that namespace.
+ Track the line number of magic method and magic properties (Instead of reporting the line number of the class).

Bug fixes
+ Fix a crash seen when using a temporary expression in a write context. (#1915)

  New issue type: `PhanInvalidWriteToTemporaryExpression`
+ Fix a crash seen with --use-fallback-parser with an invalid expression after `new`
+ Properly infer that closures have a class name of `Closure` for some issue types.
  (e.g. `call_user_func([function() {}, 'invalidMethod'])`)
+ Fix a bug analyzing nested assignment in conditionals (#1919)
+ Don't include impossibly types when analyzing assertions such as `is_string($var)` (#1932)

26 Aug 2018, Phan 1.0.1
-----------------------

New features(CLI,Configs)
+ Support setting a `target_php_version` of PHP 7.3 in the config file or through `--target-php-version`.
+ Assume that `__construct`, `__destruct`, `__set`, `__get`, `__unset`, `__clone`, and `__wakeup` have return types of void if unspecified.

New features(Analysis)
+ Add function signatures for functions added/modified in PHP 7.3. (#1537)
+ Improve the line number for warnings about unextractable `@property*` annotations.
+ Make Phan aware that `$x` is not false inside of loops such as `while ($x = dynamic_value()) {...}` (#1646)
+ Improve inferred types of `$x` in complex equality/inequality checks such as `if (($x = dynamic_value()) !== false) {...}`
+ Make `!is_numeric` assertions remove `int` and `float` from the union type of an expression. (#1895)
+ Preserve any matching original types in scalar type assertions (#1896)
  (e.g. a variable `$x` of type `?int|?MyClass` will have type `int` after `assert(is_numeric($x))`)

Maintenance:
+ Add/modify various function, methods, and property type signatures.

Plugins:
+ Add `UnknownElementTypePlugin` to warn about functions/methods
  that have param/return types that Phan can't infer anything about.
  (it can still infer some things in non-quick mode about parameters)

  New issue types: `PhanPluginUnknownMethodReturnType`, `PhanPluginUnknownClosureReturnType`, `PhanPluginUnknownFunctionReturnType`, `PhanPluginUnknownPropertyType`
+ Add `DuplicateExpressionPlugin` to warn about duplicated expressions such as:
  - `X == X`, `X || X`, and many other binary operators (for operators where it is likely to be a bug)
  - `X ? X : Y` (can often simplify to `X ?: Y`)
  - `isset(X) ? X : Y` (can simplify to `??` in PHP 7)

  New issue types: `PhanPluginDuplicateExpressionBinaryOp`, `PhanPluginDuplicateConditionalTernaryOperation`, `PhanPluginDuplicateConditionalNullCoalescing`
+ Improve types inferred for `$matches` for PregRegexCheckerPlugin.

Bug fixes:
+ Properly handle `CompileError` (that are not the subclass `ParseError`). CompileError was added in PHP 7.3.
  (Phan now logs these the same way it would log other syntax errors, instead of treating this like an unexpected Error.)
+ Make sure that private methods that are generators, that are inherited from a trait, aren't treated like a `void`.
+ Fix a crash analyzing a dynamic call to a static method, which occurred when dead code detection or reference tracking was enabled. (#1889)
+ Don't accidentally emit false positive issues about operands of binary operators in certain contexts. (#1898)

12 Aug 2018, Phan 1.0.0
-----------------------

The Phan 1.0.0 release supports analysis of php 7.0-7.2, and can be executed with php 7.0+.
This release replaces the previous 0.12.x releases.
Because Phan uses PHP's Reflection APIs, it's recommended to use the same PHP minor version for analyzing the code as would be used to run the code.
(For the small number of function/method signatures, etc., that were added or changed in each minor release of PHP.)

Plugins:
+ Plugins: Remove V1 plugins (and V1 plugin examples), as well as legacy plugin capabilities. (#249)
  Third party plugin authors must use V2 of the plugin system.

  Removed capabilities:

  - `AnalyzeNodeCapability`, `LegacyAnalyzeNodeCapability`, `LegacyPostAnalyzeNodeCapability` (use `PostAnalyzeNodeCapability` instead)
  - `LegacyPreAnalyzeNodeCapability` (use `PreAnalyzeNodeCapability` instead)
+ API: Remove various methods that were deprecated. (#249)
  Any plugins using those methods will need to be updated.
  (e.g. `Config::getValue('config_value')` must be used instead of `Config::get()->config_value`)
+ Config: Remove `progress_bar_sample_rate` (#249)
  (`progress_bar_sample_interval` must be used instead if you want the progress bar to be faster or slower)
+ Maintenance: Immediately report the exception and exit if any plugins threw an uncaught `Throwable` during initialization.
  (E.g. this results in a better error message when a third party plugin requires PHP 7.1 syntax but PHP 7.0 is used to run Phan)

21 Jul 2018, Phan 0.12.15
-------------------------

New features(Analysis)
+ Make Phan's unused variable detection also treat exception variables as variable definitions,
  and warn if the caught exception is unused. (#1810)
  New issue types: `PhanUnusedVariableCaughtException`
+ Be more aggressive about inferring that a method has a void return type, when it is safe to do so
+ Emit `PhanInvalidConstantExpression` in some places where PHP would emit `"Constant expression contains invalid operations"`

  Phan will replace the default parameter type (or constant type) with `mixed` for constants and class constants.

  Previously, this could cause Phan to crash, especially with `--use-fallback-parser` on invalid ASTs.
+ Improve analysis of arguments passed to `implode()`

New features(CLI)
+ Add `--daemonize-tcp-host` CLI option for specifying the hostname for daemon mode (#1868).
  The default will remain `127.0.0.1` when not specified.
  It can be overidden to values such as `0.0.0.0` (publicly accessible, e.g. for usage with Docker)

Language Server/Daemon mode:
+ Implement support for hover requests in the Language Server (#1738)

  This will show a preview of the element definition (showing signature types instead of PHPDoc types)
  along with the snippet of the element description from the doc comment.

  Clients that use this should pass in the CLI option `--language-server-enable-hover` when starting the language server.

  - Note that this implementation assumes that clients sanitize the mix of markdown and HTML before rendering it.
  - Note that this may slow down some language server clients if they pause while waiting for the hover request to finish.

Maintenance:
+ Add a workaround for around a notice in PHP 7.3alpha4  (that Phan treats as fatal) (#1870)

Bug fixes:
+ Fix a bug in checking if nullable versions of specialized type were compatible with other nullable types. (#1839, #1852)
  Phan now correctly allows the following type casts:

  - `?1`               can cast to `?int`
  - `?'a string'`      can cast to `?string`
  - `?Closure(T1):T2`  can cast to `?Closure`
  - `?callable(T1):T2` can cast to `?callable`,
+ Make `exclude_file_list` work more consistently on Windows

08 Jul 2018, Phan 0.12.14
-------------------------

New features(CLI, Configs)
+ Add `warn_about_undocumented_throw_statements` and `exception_classes_with_optional_throws_phpdoc` config. (#90)

  If `warn_about_undocumented_throw_statements` is true, Phan will warn about uncaught throw statements that aren't documented in the function's PHPDoc.
  (excluding classes listed in `exception_classes_with_optional_throws_phpdoc` and their subclasses)
  This does not yet check function and method calls within the checked function that may themselves throw.

  Add `warn_about_undocumented_exceptions_thrown_by_invoked_functions`.
  If enabled (and `warn_about_undocumented_throw_statements` is enabled),
  Phan will warn about function/closure/method invocations that have `@throws`
  that aren't caught or documented in the invoking method.
  New issue types: `PhanThrowTypeAbsent`, `PhanThrowTypeAbsentForCall`,
  `PhanThrowTypeMismatch`, `PhanThrowTypeMismatchForCall`

  Add `exception_classes_with_optional_throws_phpdoc` config.
  Phan will not warn about lack of documentation of `@throws` for any of the configured classes or their subclasses.
  The default is the empty array (Don't suppress any warnings.)
  (E.g. Phan suppresses `['RuntimeException', 'AssertionError', 'TypeError']` for self-analysis)

New Features (Analysis):
+ Warn when string literals refer to invalid class names (E.g. `$myClass::SOME_CONSTANT`). (#1794)
  New issue types: `PhanTypeExpectedObjectOrClassNameInvalidName` (emitted if the name can't be used as a class)
  This will also emit `PhanUndeclaredClass` if the class name could not be found.
+ Make Phan aware that `$this` doesn't exist in a static closure (#768)

Language Server/Daemon mode:
+ Fix another rare bug that can cause crashes in the polyfill/fallback parser when parsing invalid or incomplete ASTs.
+ Add a `--language-server-hide-category` setting to hide the issue category from diagnostic messages.
+ Remove the numeric diagnostic code from the language server diagnostics (a.k.a. issues).
  (Certain language clients such as LanguageClient-neovim would render that the code in the quickfix menu, wasting space)
+ Support "go to definition" for union types within all code comment types  (#1704)
  (e.g. can go to definition in `// some annotation or comment mentioning MyType`)

New features(Analysis)
+ Support analysis of [`list()` reference assignment](https://wiki.php.net/rfc/list_reference_assignment) for php 7.3 (which is still in alpha). (#1537)
+ Warn about invalid operands of the unary operators `+`, `-`, and `~`
  New issue types: `PhanTypeInvalidUnaryOperandNumeric` and `PhanTypeInvalidUnaryOperandBitwiseNot` (#680)

Bug fixes:
+ Fix a bug causing Phan to infer extra wrong types (`ancestorClass[][]`) for `@return className[]` (#1822)
+ Start warning about assignment operations (e.g. `+=`) when the modified variable isn't referenced later in the function.
+ Make exceptions in `catch{}` always include the type `Throwable` even if the declared type doesn't. (#336)

16 Jun 2018, Phan 0.12.13
-------------------------

New features(Analysis)
+ Support integer literals both in PHPDoc and in Phan's type system. (E.g. `@return -1|string`)
  Include integer values in issue messages if the values are known.
+ Support string literals both in PHPDoc and in Phan's type system. (E.g. `@return 'example\n'`)
  Phan can now infer possible variable values for dynamic function/method calls, etc.

  Note: By default, Phan does not store representations of strings longer than 50 characters. This can be increased with the `'max_literal_string_type_length'` config.

  Supported escape codes: `\\`, `\'`, `\r`, `\n`, `\t`, and hexadecimal (`\xXX`).
+ Improve inferred types of unary operators.
+ Warn about using `void`/`iterable`/`object` in use statements based on `target_php_version`. (#449)
  New issue types: `PhanCompatibleUseVoidPHP70`, `PhanCompatibleUseObjectPHP71`, `PhanCompatibleUseObjectPHP71`
+ Warn about making overrides of inherited property and constants less visible (#788)
  New issue types: `PhanPropertyAccessSignatureMismatch`, `PhanPropertyAccessSignatureMismatchInternal`,
  `PhanConstantAccessSignatureMismatch`, `PhanConstantAccessSignatureMismatchInternal`.
+ Warn about making static properties into non-static properties (and vice-versa) (#615)
  New issue types: `PhanAccessNonStaticToStaticProperty`, `PhanAccessStaticToNonStaticProperty`
+ Warn about inheriting from a class/trait/interface that has multiple possible definitions (#773)
  New issue types: `PhanRedefinedExtendedClass`, `PhanRedefinedUsedTrait`, `PhanRedefinedInheritedInterface`
+ Infer more accurate types for the side effects of assignment operators (i.e. `+=`, `.=`, etc) and other binary operations. (#1775)
+ Warn about invalid arguments to binary operators or assignment operators.
  New issue types: `PhanTypeInvalidLeftOperandOfAdd`,  `PhanTypeInvalidLeftOperandOfNumericOp`,
                   `PhanTypeInvalidRightOperandOfAdd`, `PhanTypeInvalidRightOperandOfNumericOp`
+ Warn about using negative string offsets and multiple catch exceptions in PHP 7.0 (if `target_php_version` is less than `'7.1'`). (#1771, #1778)
  New issue types: `PhanCompatibleMultiExceptionCatchPHP70`, `PhanCompatibleNegativeStringOffset`.

Maintenance:
+ Update signature map with more accurate signatures (#1761)
+ Upgrade tolerant-php-parser, making the polyfill/fallback able to parse PHP 7.1's multi exception catch.

Bug fixes:
+ Don't add more generic types to properties with more specific PHPDoc types (#1783).
  For example, don't add `array` to a property declared with PHPDoc type `/** @var string[] */`
+ Fix uncaught `AssertionError` when `parent` is used in PHPDoc (#1758)
+ Fix various bugs that can cause crashes in the polyfill/fallback parser when parsing invalid or incomplete ASTs.
+ Fix unparseable/invalid function signature entries of rarely used functions
+ Warn about undefined variables on the left hand side of assignment operations (e.g. `$x .= 'string'`) (#1613)

08 Jun 2018, Phan 0.12.12
-------------------------

Maintenance:
+ Increase the severity of some issues to critical
  (if they are likely to cause runtime Errors in the latest PHP version).

Bug fixes:
+ Allow suppressing `PhanTypeInvalidThrows*` with doc comment suppressions
  in the phpdoc of a function/method/closure.
+ Fix crashes when fork pool is used and some issue types are emitted (#1754)
+ Catch uncaught exception for PhanContextNotObject when calling `instanceof self` outside a class scope (#1754)

30 May 2018, Phan 0.12.11
-------------------------

Language Server/Daemon mode:
+ Make the language server work more reliably when `pcntl` is unavailable. (E.g. on Windows) (#1739)
+ By default, allow the language server and daemon mode to start with the fallback even if `pcntl` is unavailable.
  (`--language-server-require-pcntl` can be used to make the language server refuse to start without `pcntl`)

Bug fixes:
+ Don't crash if `ext-tokenizer` isn't installed (#1747)
+ Fix invalid output of `tool/make_stubs` for apcu (#1745)

27 May 2018, Phan 0.12.10
-------------------------

New features(CLI, Configs)
+ Add CLI flag `--unused-variable-detection`.
+ Add config setting `unused_variable_detection` (disabled by default).
  Unused variable detection can be enabled by `--unused-variable-detection`, `--dead-code-detection`, or the config.

New features(Analysis):
+ Add built-in support for unused variable detection. (#345)
  Currently, this is limited to analyzing inside of functions, methods, and closures.
  This has some minor false positives with loops and conditional branches.

  Warnings about unused parameters can be suppressed by adding `@phan-unused-param` on the same line as `@param`,
  e.g. `@param MyClass $x @phan-unused-param`.
  (as well as via standard issue suppression methods.)

  The built in unused variable detection support will currently not warn about any of the following issue types, to reduce false positives.

  - Variables beginning with `$unused` or `$raii` (case insensitive)
  - `$_` (the exact variable name)
  - Superglobals, used globals (`global $myGlobal;`), and static variables within function scopes.
  - Any references, globals, or static variables in a function scope.

  New Issue types:
  - `PhanUnusedVariable`,
  - `PhanUnusedVariableValueOfForeachWithKey`, (has a high false positive rate)
  - `PhanUnusedPublicMethodParameter`, `PhanUnusedPublicFinalMethodParameter`,
  - `PhanUnusedProtectedMethodParameter`, `PhanUnusedProtectedFinalMethodParameter`,
  - `PhanUnusedPrivateMethodParameter`, `PhanUnusedProtectedFinalMethodParameter`,
  - `PhanUnusedClosureUseVariable`, `PhanUnusedClosureParameter`,
  - `PhanUnusedGlobalFunctionParameter`

  This is similar to the third party plugin `PhanUnusedVariable`.
  The built-in support has the following changes:

  - Emits fewer/different false positives (e.g. when analyzing loops), but also detects fewer potential issues.
  - Reimplemented using visitors extensively (Similar to the code for `BlockAnalysisVisitor`)
  - Uses a different data structure from `PhanUnusedVariable`.
    This represent all definitions of a variable, instead of just the most recent one.
    This approximately tracks the full graph of definitions and uses of variables within a function body.
    (This allows warning about all unused definitions, or about definitions that are hidden by subsequent definitions)
  - Integration: This is planned to be integrated with other features of Phan, e.g. "Go to Definition" for variables. (Planned for #1211 and #1705)

Bug fixes:
+ Minor improvements to `UnusedSuppressionPlugin`

Misc:
+ Support  `composer.json`'s `vendor-dir` for `phan --init`

22 May 2018, Phan 0.12.9
------------------------

New features(CLI, Configs):
+ Add CLI flag `--language-server-enable-go-to-definition`. See the section "Language Server/Daemon mode".
+ Add Config setting `disable_line_based_suppression` to disable line-based suppression from internal comments. See the section "New Features"
+ Add Config setting `disable_file_based_suppression` to disable file-based issue suppressions.

New features(Analysis):
+ Make `@suppress`, `@phan-suppress`, `@phan-file-suppress` accept a comma separated issue list of issue types to suppress. (#1715)
  Spaces aren't allowed before the commas.
+ Implement `@phan-suppress-current-line` and `@phan-suppress-next-line` to suppress issues on the current or next line.

  These can occur within any comment or doc comment (i.e. the comment types for `/*`, `//`, and `/**`)

  These suppressions accept a comma separated list of issue type names.
  Commas must be immediately after the previous issue type.

  Note: Phan currently does not support inline comments anywhere else.
  Phan also does not associate these inline comments with any information about the current scope.
  This suppression is based on tokenizing the PHP file and determining the line based on that comment line.

  Examples:

  ```php
  // @phan-suppress-next-line PhanUndeclaredVariable, PhanUndeclaredFunction optional reason goes here
  $result = call_undefined_function() + $undefined_variable;

  $closure();  /* @phan-suppress-current-line PhanParamTooFew optional reason for suppression */

  /**
   * This can also be used within doc comments:

   * @phan-suppress-next-line PhanInvalidCommentForDeclarationType optional reason for suppression
   * @property int $x
   */
  function my_example() {
  }
  ```

  `PhanUnusedSuppressionPlugin` is capable of detecting if line-based suppressions are unused.
+ Allow using `@phan-file-suppress` as a regular comment anywhere within a file (`//`, `/*`, or `/**` comments).
  Previously, `@phan-file-suppress` could only be used inside the doc comment of an element.

  `@phan-file-suppress` in no-op string literals will be deprecated in a future Phan release.
+ Emit class name suggestions for undeclared types in param, property, return type, and thrown type declarations. (#1689)

  Affects `PhanUndeclaredTypeParameter`, `PhanUndeclaredTypeProperty`, `PhanUndeclaredTypeReturnType`,
  `PhanUndeclaredTypeThrowsType`, and `PhanInvalidThrowsIs*`
+ Add `pretend_newer_core_methods_exist` config setting.
  If this is set to true (the default),
  and `target_php_version` is newer than the version used to run Phan,
  Phan will act as though functions added in newer PHP versions exist.

  Note: Currently only affects `Closure::fromCallable()`, which was added in PHP 7.1.
  This will affect more functions and methods in the future.

Language Server/Daemon mode:
+ Support "Go to definition" for properties, classes, global/class constants, and methods/global functions (Issue #1483)
  (Must pass the CLI option `--language-server-enable-go-to-definition` when starting the server to enable this)
+ Support "Go to type definition" for variables, properties, classes, and methods/global functions (Issue #1702)
  (Must pass the CLI option `--language-server-enable-go-to-definition` when starting the server to enable this)
  Note that constants can't have object types in PHP, so there's no implementation of "Go To Type Definition" for those.

Plugins:
+ Add a new plugin capability `SuppressionCapability`
  that allows users to suppress issues in additional ways. (#1070)
+ Add a new plugin `SleepCheckerPlugin`. (PR #1696)
  Warn about returning non-arrays in sleep,
  as well as about returning array values with invalid property names.

  Issue types: `SleepCheckerInvalidReturnStatement`, `SleepCheckerInvalidPropNameType`, `SleepCheckerInvalidPropName`,
  `SleepCheckerMagicPropName`, and `SleepCheckerDynamicPropName`
+ Make `PhanPregRegexCheckerPlugin` warn about the `/e` modifier on regexes (#1692)

Misc:
+ Add simple integration test for the language server mode.

Bug fixes:
+ Be more consistent about emitting `PhanUndeclaredType*` for invalid types within array shapes.
+ Avoid a crash when the left hand side of an assignment is invalid. (#1693)
+ Prevent an uncaught `TypeError` when integer variable names (e.g. `${42}`) are used in branches (Issue #1699)

12 May 2018, Phan 0.12.8
------------------------

Bug fixes
+ Fix a crash that occurs when the `iterable<[KeyType,]ValueType>` annotation is used in phpdoc. (#1685)

08 May 2018, Phan 0.12.7
------------------------

New features:
+ For `PhanUndeclaredMethod` and `PhanUndeclaredStaticMethod` issues, suggest visible methods (in the same class) with similar names.
+ For `PhanUndeclaredConstant` issues (for class constants), suggest visible constants (in the same class) with similar names.
+ For `PhanUndeclaredProperty` and `PhanUndeclaredStaticProperty` issues, suggest visible properties (in the same class) with similar names.
+ When suggesting alternatives to undeclared classes,
  also include suggestions for similar class names within the same namespace as the undeclared class.
  (Comparing Levenshtein distance)

Language Server/Daemon mode
+ Make the latest version of `phan_client` include any suggestion alongside the issue message (for daemon mode).
+ Include text from suggestions in Language Server Protocol output

Bug fixes
+ Fix a bug generating variable suggestions when there were multiple similar variable names
  (The suggestions that would show up might not be the best set of suggestions)
+ Fix a crash in the tolerant-php-parser polyfill seen when typing out an echo statement
+ Fix incorrect suggestions to use properties (of the same name) instead of undeclared variables in class scopes.
  (Refer to static properties as `self::$name` and don't suggest inaccessible inherited private properties)
+ Don't suggest obviously invalid alternatives to undeclared classes.
  (E.g. don't suggest traits or interfaces for `new MisspelledClass`, don't suggest interfaces for static method invocations)

06 May 2018, Phan 0.12.6
------------------------

New features(Analysis)
+ Warn about properties that are read but not written to when dead code detection is enabled
  (Similar to existing warnings about properties that are written to but never read)
  New issue types: `PhanReadOnlyPrivateProperty`, `PhanReadOnlyProtectedProperty`, `PhanReadOnlyPublicProperty`
+ When warning about undeclared classes, mention any classes that have the same name (but a different namespace) as suggestions.

  E.g. `test.php:26 PhanUndeclaredClassInstanceof Checking instanceof against undeclared class \MyNS\InvalidArgumentException (Did you mean class \InvalidArgumentException)`
+ When warning about undeclared variables (outside of the global scope), mention any variables that have similar names (based on case-insensitive Levenshtein distance) as suggestions.

  In method scopes: If `$myName` is undeclared, but `$this->myName` is declared (or inherited), `$this->myName` will be one of the suggestions.
+ Warn about string and numeric literals that are no-ops. (E.g. `<?php 'notEchoedStr'; "notEchoed $x"; ?>`)
  New issue types: `PhanNoopStringLiteral`, `PhanNoopEncapsulatedStringLiteral`, `PhanNoopNumericLiteral`.

  Note: This will not warn about Phan's [inline type checks via string literals](https://github.com/phan/phan/wiki/Annotating-Your-Source-Code#inline-type-checks-via-string-literals)
+ When returning an array literal (with known keys) directly,
  make Phan infer the array literal's array shape type instead of a combination of generic array types.
+ Make type casting rules stricter when checking if an array shape can cast to a given generic array type.
  (E.g. `array{a:string,b:int}` can no longer cast to `array<string,int>`, but can cast to `array<string,int>|array<string,string>`).

  E.g. Phan will now warn about `/** @return array<string,int> */ function example() { $result = ['a' => 'x', 'b' => 2]; return $result; }`
+ Warn about invalid expressions/variables encapsulated within double-quoted strings or within heredoc strings.
  New issue type: `TypeSuspiciousStringExpression` (May also emit `TypeConversionFromArray`)

+ Add support for template params in iterable types in phpdoc. (#824)
  Phan supports `iterable<TValue>` and `iterable<TKey, TValue>` syntaxes. (Where TKey and TValue are union types)
  Phan will check that generic arrays and array shapes can cast to iterable template types.
+ Add support for template syntax of Generator types in phpdoc. (#824)
  Supported syntaxes are:

  1. `\Generator<TValue>`
  2. `\Generator<TKey,TValue>`
  3. `\Generator<TKey,TValue,TSend>` (TSend is the expected type of `$x` in `$x = yield;`)
  4. `\Generator<TKey,TValue,TSend,TReturn>` (TReturn is the expected type of `expr` in `return expr`)

  New issue types: `PhanTypeMismatchGeneratorYieldValue`, `PhanTypeMismatchGeneratorYieldKey` (For comparing yield statements against the declared `TValue` and `TKey`)

  Additionally, Phan will use `@return Generator|TValue[]` to analyze the yield statements
  within a function/method body the same way as it would analyze `@return Generator<TValue>`.
  (Analysis outside the method would not change)

+ Add support for template params in Iterator and Traversable types in phpdoc. (#824)
  NOTE: Internal subtypes of those classes (e.g. ArrayObject) are not supported yet.
  Supported syntaxes are:

  1. `Traversable<TValue>`/`Iterator<TValue>`
  2. `Traversable<TKey,TValue>`/`Iterator<TKey,TValue>`

+ Analyze `yield from` statements.

  New issue types: `PhanTypeInvalidYieldFrom` (Emitted when the expression passed to `yield from` is not a Traversable or an array)

  Warnings about the inferred keys/values of `yield from` being invalid reuse `PhanTypeMismatchGeneratorYieldValue` and `PhanTypeMismatchGeneratorYieldKey`
+ Make the union types within the phpdoc template syntax of `iterator`/`Traversable`/`Iterator`/`Generator` affect analysis of the keys/values of `foreach` statements
+ Improve phan's analysis of array functions modifying arguments by reference, reducing false positives. (#1662)
  Affects `array_shift`/`array_unshift`/`array_push`/`array_pop`/`array_splice`.

Misc
+ Infer that a falsey array is the empty array shape.

Bug Fixes
+ Consistently warn about unreferenced declared properties (i.e. properties that are not magic or dynamically added).
  Previously, Phan would just never warn if the class had a `__get()` method (as a heuristic).

03 Apr 2018, Phan 0.12.5
------------------------

Plugins
+ Add an option `'php_native_syntax_check_max_processes'` to `'plugin_config'` for `InvokePHPNativeSyntaxCheckPlugin`.

Bug Fixes
+ Remove extra whitespace from messages of comment text in `UnextractableAnnotationElementName` (e.g. `"\r"`)
+ Fix bugs in `InvokePHPNativeSyntaxCheckPlugin`

31 Mar 2018, Phan 0.12.4
------------------------

New Features(CLI, Configs)
+ Add a `strict_param_checking` config setting. (And a `--strict-param-checking` CLI flag)
  If this is set to true, then Phan will warn if at least one of the types
  in an argument's union type can't cast to the expected parameter type.
  New issue types: `PhanPartialTypeMismatchArgument`, `PhanPossiblyNullTypeArgument`, and `PhanPossiblyFalseTypeArgument`
  (along with equivalents for internal functions and methods)

  Setting this to true will likely introduce large numbers of warnings.
  Those issue types would need to be suppressed entirely,
  or with `@phan-file-suppress`, or with `@suppress`.
+ Add a `strict_property_checking` config setting. (And a `--strict-property-checking` CLI flag)
  If this is set to true, then Phan will warn if at least one of the types
  in an assignment's union type can't cast to the expected property type.
  New issue types: `PhanPartialTypeMismatchProperty`, `PhanPossiblyNullTypeProperty`, and `PhanPossiblyFalseTypeProperty`

  NOTE: This option does not make Phan check if all possible expressions have a given property, but may do that in the future.
+ Add a `strict_return_checking` config setting. (And a `--strict-return-checking` CLI flag)
  If this is set to true, then Phan will warn if at least one of the types
  in a return statement's union type can't cast to the expected return type type.
  New issue types: `PhanPartialTypeMismatchReturn`, `PhanPossiblyNullTypeReturn`, and `PhanPossiblyFalseTypeReturn`

  Setting this to true will likely introduce large numbers of warnings.
  Those issue types would need to be suppressed entirely,
  or with `@phan-file-suppress`, or with `@suppress`.
+ Add a `--strict-type-checking` CLI flag, to enable all of the new strict property/param/return type checks.
+ Add a `guess_unknown_parameter_type_using_default` config,
  which can be enabled to make Phan more aggresively infer the types of undocument optional parameters
  from the parameter's default value.
  E.g. `function($x = 'val')` would make Phan infer that the function expects $x to have a type of `string`, not `string|mixed`.

Plugins
+ Add a new plugin `InvokePHPNativeSyntaxCheckPlugin` on all analyzed files (but not files excluded from analysis) (#629)
+ Add a new plugin capability `AfterAnalyzeFileCapability` that runs after a given file is analyzed.
  This does not get invoked for files that are excluded from analysis, or for empty files.

New Features(Analysis)
+ Detect unreachable catch statements (#112)
  (Check if an earlier catch statement caught an ancestor of a given catch statement)
+ Support phpdoc3's `scalar` type in phpdoc. (#1589)
  That type is equivalent to `bool|float|int|string`.
+ Improve analysis of return statements with ternary conditionals (e.g. `return $a ?: $b`).
+ Start analyzing negated `instanceof` conditionals such as `assert(!($x instanceof MyClass))`.
+ Infer that the reference parameter's resulting type for `preg_match` is a `string[]`, not `array` (when possible)
  (And that the type is `array{0:string,1:int}[]` when `PREG_OFFSET_CAPTURE` is passed as a flag)
+ Warn in more places when Phan can't extract union types or element identifiers from a doc comment.
  New issue types: `UnextractableAnnotationElementName`, `UnextractableAnnotationSuffix`.
  (E.g. warn about `@param int description` (ideally has param name) and `@return int?` (Phan doesn't parse the `?`, should be `@return ?int`))

Bug Fixes
+ Don't emit false positive `PhanTypeArraySuspiciousNullable`, etc. for complex isset/empty/unset expressions. (#642)
+ Analyze conditionals wrapped by `@(cond)` (e.g. `if (@array_key_exists('key', $array)) {...}`) (#1591)
+ Appending an unknown type to an array shape should update Phan's inferred keys(int) and values(mixed) of an array. (#1560)
+ Make line numbers for arguments more accurate
+ Infer that the result of `|` or `&` on two strings is a string.
+ Fix a crash caused by empty FQSENs for classlike names or function names (#1616)

24 Mar 2018, Phan 0.12.3
------------------------

New Features(CLI, Configs)
+ Add `--polyfill-parse-all-element-doc-comments` for PHP 7.0.
  If you're using the polyfill (e.g. using `--force-polyfill-parser`), this will parse doc comments on class constants in php 7.0.
  (Normally, the polyfill wouldn't include that information, to closely imitate `php-ast`'s behavior)

New Features(Analysis)
+ Infer the type of `[]` as `array{}` (the empty array), not `array`. (#1382)
+ Allow phpdoc `@param` array shapes to contain optional fields. (E.g. `array{requiredKey:int,optionalKey?:string}`) (#1382)
  An array shape is now allowed to cast to another array shape, as long as the required fields are compatible with the target type,
  and any optional fields from the target type are absent in the source type or compatible.
+ In issue messages, represent closures by their signatures instead of as `\closure_{hexdigits}`
+ Emit `PhanTypeArrayUnsetSuspicious` when trying to unset the offset of something that isn't an array or array-like.
+ Add limited support for analyzing `unset` on variables and the first dimension of arrays.
  Unsetting variables does not yet work in conditional branches.
+ Don't emit `PhanTypeInvalidDimOffset` in `isset`/`empty`/`unset`
+ Improve Phan's analysis of loose equality (#1101)
+ Add new issue types `PhanWriteOnlyPublicProperty`, `PhanWriteOnlyProtectedProperty`, and `PhanWriteOnlyPrivateProperty`,
  which will be emitted on properties that are written to but never read from.
  (Requires that dead code detection be enabled)
+ Improve Phan's analysis of switch statements and fix bugs. (#1561)
+ Add `PhanTypeSuspiciousEcho` to warn about suspicious types being passed to echo/print statements.
  This now warns about booleans, arrays, resources, null, non-stringable classes, combinations of those types, etc.
  (`var_export` or JSON encoding usually makes more sense for a boolean/null)
+ Make Phan infer that top level array keys for expressions such as `if (isset($x['keyName']))` exist and are non-null. (#1514)
+ Make Phan infer that top level array keys for expressions such as `if (array_key_exists('keyName', $x))` exist. (#1514)
+ Make Phan aware of types after negated of `isset`/`array_key_exists` checks for array shapes (E.g. `if (!array_key_exists('keyName', $x)) { var_export($x['keyName']); }`)
  Note: This will likely fail to warn if the variable is already a mix of generic arrays and array shapes.
+ Make Phan check that types in `@throws` annotations are valid; don't warn about classes in `@throws` being unreferenced. (#1555)
  New issue types: `PhanUndeclaredTypeThrowsType`, `PhanTypeInvalidThrowsNonObject`, `PhanTypeInvalidThrowsNonThrowable`, `PhanTypeInvalidThrowsIsTrait`, `PhanTypeInvalidThrowsIsInterface`

New types:
+ Add `Closure` and `callable` with annotated param types and return to Phan's type system(#1578, #1581).
  This is not a part of the phpdoc2 standard or any other standard.
  These can be used in any phpdoc tags that Phan is aware of,
  to indicate their expected types (`@param`, `@var`, `@return`, etc.)

  Examples:

  - `function(int $x) : ?int {return $x;}` has the type `Closure(int):?int`,
    which can cast to `callable(int):?int`
  - `function(array &$x) {$x[] = 2;}` has the type `Closure(array&):void`
  - `function(int $i = 2, int ...$args) : void {}`
    has the type `Closure(int=,int...):void`

  Note: Complex return types such as `int[]` or `int|false`
  **must** be surrounded by brackets to avoid potential ambiguities.

  - e.g. `Closure(int|array): (int[])`
  - e.g. `Closure(): (int|false)`
  - e.g. `Closure(): (array{key:string})` is not ambiguous,
    but the return type must be surrounded by brackets for now

  Other notes:
  - For now, the inner parameter list of `Closure(...)`
    cannot contain the characters `(` or `)`
    (or `,`, except to separate the arguments)
    Future changes are planned to allow those characters.
  - Phan treats `Closure(T)` as an alias of `Closure(T):void`
  - Placeholder variable names can be part of these types,
    similarly to `@method` (`Closure($unknown,int $count=0):T`
    is equivalent to `Closure(mixed,int):T`

Maintenance
+ Add `--disable-usage-on-error` option to `phan_client` (#1540)
+ Print directory which phan daemon is going to await analysis requests for (#1544)
+ Upgrade the dependency `Microsoft/tolerant-php-parser` to 0.0.10 (includes minor bug fixes)

Bug Fixes
+ Allow phpdoc `@param` array shapes to contain union types (#1382)
+ Remove leading `./` from Phan's relative paths for files (#1548, #1538)
+ Reduce false positives in dead code detection for constants/properties/methods.
+ Don't warn when base classes access protected properties of their subclasses.

02 Mar 2018, Phan 0.12.2
------------------------

New Features(Analysis)

+ Emit `PhanTypeInvalidDimOffsetArrayDestructuring` when an unknown offset value is used in an array destructuring assignment (#1534, #1477)
  (E.g. `foreach ($expr as ['key' => $value])`, `list($k) = [2]`, etc.)

Plugins
+ Add a new plugin capability `PostAnalyzeNodeCapability` (preferred) and `LegacyPostAnalyzeNodeCapability`.
  These capabilities give plugins for post-order analysis access to a list of parent nodes,
  instead of just the last parent node.
  Plugin authors should use these instead of `AnalyzeNodeCapability` and `LegacyAnalyzeNodeCapability`.

  (`parent_node_list` is set as an instance property on the visitor returned by PostAnalyzeNodeCapability
  if the instance property was declared)

Maintenance:
+ Speed up analysis when quick mode isn't used.

Bug Fixes
+ Reduce false positives in `PhanTypeInvalidDimOffset`
+ Don't warn when adding new keys to an array when assigning multiple dimensions at once (#1518)
+ Reduce false positives when a property's type gets inferred as an array shape(#1520)
+ Reduce false positives when adding fields to an array in the global scope.
+ Reduce false positives by converting array shapes to generic arrays before recursively analyzing method/function invocations (#1525)

28 Feb 2018, Phan 0.12.1
------------------------

New Features(Analysis)
+ Emit `PhanTypeInvalidDimOffset` when an unknown offset is fetched from an array shape type. (#1478)

Bug Fixes
+ Fix an "Undefined variable" error when checking for php 7.1/7.0 incompatibilities in return types. (#1511)
  Fix other crashes.

25 Feb 2018, Phan 0.12.0
------------------------

The Phan 0.12.0 release supports analysis of php 7.0-7.2, and can be executed with php 7.0+.
This release replaces the previous releases (The 0.11 releases for php 7.2, the 0.10 releases for php 7.1, and the 0.8 releases for php 7.0)
Because Phan uses Reflection, it's recommended to use the same PHP minor version for analyzing the code as would be used to run the code.
(For the small number of function/method signatures, etc., that were added or changed in each minor release of PHP.)

After upgrading Phan, projects using phan should add a `target_php_version` setting to their `.phan/config.php`.

New Features(CLI, Configs)
+ Add a `target_php_version` config setting, which can be set to `'7.0'`, `'7.1'`, `'7.2'`, or `null`/`'native'`. (#1174)
  This defaults to the same PHP minor version as the PHP binary used to run Phan.
  `target_php_version` can be overridden via the CLI option `--target-php-version {7.0,7.1,7.2,native}`

  NOTE: This setting does not let a PHP 7.0 installation parse PHP 7.1 nullable syntax or PHP 7.1 array destructuring syntax.

  If you are unable to upgrade the PHP version used for analysis to php 7.1, the polyfill parser settings may help
  (See `--force-polyfill-parser` or `--use-fallback-parser`. Those have a few known bugs in edge cases.)
+ Add `--init` CLI flag and CLI options to affect the generated config. (#145)
  (Options: `--init-level=1..5`, `--init-analyze-dir=path/to/src`, `--init-analyze-file=path/to/file.php`, `--init-no-composer`, `--init-overwrite`)

New Features(Analysis)
+ In doc comments, support `@phan-var`, `@phan-param`, `@phan-return`, `@phan-property`, and `@phan-method`. (#1470)
  These annotations will take precedence over `@var`, `@param`, `@return`, `@property`, and `@method`.
+ Support `@phan-suppress` as an alias of `@suppress`.
+ Add a non-standard way to explicitly set var types inline.  (#890)
  `; '@phan-var T $varName'; expression_using($varName);` and
  `; '@phan-var-force T $varName'; expression_using($varName);`

  If Phan sees a string literal containing `@phan-var` in the top level of a statement list, it will immediately set the type of `$varName` to `T` without any type checks.
  (`@phan-var-force T $x` will do the same thing, and will create the variable if it didn't already exist).

  Note: Due to limitations of the `php-ast` parser, Phan isn't able to use inline doc comments, so this is the solution that was used instead.

  Example Usage:

  ```php
  $values = mixed_expression();

  // Note: This annotation must go **after** setting the variable.
  // This has to be a string literal; phan cannot parse inline doc comments.
  '@phan-var array<int,MyClass> $values';

  foreach ($x as $instance) {
      function_expecting_myclass($x);
  }
  ```
+ Add a way to suppress issues for the entire file (including within methods, etc.) (#1190)
  The `@phan-file-suppress` annotation can also be added to phpdoc for classes, etc.
  This feature is recommended for use at the top of the file or on the first class in the file.
  It may or may not affect statements above the suppression.
  This feature may fail to catch certain issues emitted during the parse phase.

  ```php
  <?php
  // Add a suppression for remaining statements in this file.
  '@phan-file-suppress PhanUnreferencedUseNormal (description)';
  use MyNS\MyClass;
  // ...

  /** @SomeUnreadableAnnotation {MyClass} */
  class Example { }
  ```

+ Add `CompatibleNullableTypePHP70`, `CompatibleShortArrayAssignPHP70`, `CompatibleKeyedArrayAssignPHP70`,
  `CompatibleKeyedArrayAssignPHP70`, and `CompatibleIterableTypePHP70`, (#1174, #624, #449)
  which are emitted when the `target_php_version` is less than '7.1'.
+ Add `CompatibleObjectTypePHP71`, which is emitted for the `object` typehint when the `target_php_version`
  is less than 7.2. (#1174, #827)
+ Add `PhanTypeMismatchDimFetchNullable`, which is emitted if the non-null
  version of the dimension type would be a valid index. (#1472)
+ Emit `PhanTypeArraySuspiciousNullable` when accessing fields of a nullable array (now including `?(T[])`, etc.). (#1472)
  (Stop emitting PhanTypeArraySuspicious for `?array`)
+ Add `PhanNoopBinaryOperator` and `PhanNoopUnaryOperator` checks (#1404)
+ Add `PhanCommentParamOutOfOrder` code style check. (#1401)
  This checks that `@param` annotations appear in the same order as the real parameters.
+ Detect unused imports (Does not parse inline doc comments) (#1095)
  Added `PhanUnreferencedUseNormal`, `PhanUnreferencedUseFunction`, `PhanUnreferencedUseConstant`.

  (Note that Phan does not parse inline doc comments, which may cause false positives for `PhanUnreferencedUseNormal`)
+ Add `PhanTypeMismatchArrayDestructuringKey` checks for invalid array key types in list assignments (E.g. `list($x) = ['key' => 'value']` (#1383)

Language Server
+ Support running Language Server and daemon mode on Windows (#819)
  (the `pcntl` dependency is no longer mandatory for running Phan as a server)
  The `--language-server-allow-missing-pcntl` option must be set by the client.

  When this fallback is used, Phan **manually** saves and restores the
  data structures that store information about the project being analyzed.

  This fallback is new and experimental.
+ Make Phan Language Server analyze new files added to a project (Issue #920)
+ Analyze all of the PHP files that are currently opened in the IDE
  according to the language server client,
  instead of just the most recently edited file (Issue #1147)
  (E.g. analyze other files open in tabs or split windows)
+ When closing or deleting a file, clear the issues that were emitted
  for that file.
+ If analysis requests (opening files, editing files, etc)
  are arriving faster than Phan can analyze and generate responses,
  then buffer the file changes (until end of input)
  and then begin to generate analysis results.

  Hopefully, this should reduce the necessity for limiting Phan to
  analyzing only on save.

Bug fixes
+ In files with multiple namespaces, don't use `use` statements from earlier namespaces. (#1096)
+ Fix bugs analyzing code using functions/constants provided by group use statements, in addition to `use function` and `use const` statements.

14 Feb 2018, Phan 0.11.3
------------------------

### Ported from Phan 0.10.5

New Features(CLI, Configs)
+ Add `--allow-polyfill-parser` and `--force-polyfill-parser` options.
  These allow Phan to be run without installing `php-ast`.

  Using the native php-ast extension is still recommended.
  The polyfill is slower and has several known bugs.

  Additionally, the way doc comments are parsed by the polyfill is different.
  Doc comments for elements such as closures may be parsed differently from `php-ast`

Maintenance:
+ Fix bugs in the `--use-fallback-parser` mode.
  Upgrade the `tolerant-php-parser` dependency (contains bug fixes and performance improvements)

Bug fixes
+ Fix a bug in `tool/make_stubs` when generating stubs of namespaced global functions.
+ Fix a refactoring bug that caused methods and properties to fail to be inherited (#1456)
+ If `ignore_undeclared_variables_in_global_scope` is true, then analyze `assert()`
  and conditionals in the global scope as if the variable was defined after the check.

11 Feb 2018, Phan 0.11.2
------------------------

### Ported from Phan 0.10.4

New Features(Analysis)

+ Support array key types of `int`, `string`, and `mixed` (i.e. `int|string`) in union types such as `array<int,T>` (#824)

  Check that the array key types match when assigning expected param types, return types, property types, etc.
  By default, an array with a key type of `int` can't cast to an array key type of `string`, or vice versa.
  Mixed union types in keys can cast to/from any key type.

  - To allow casting `array<int,T>` to `array<string,T>`, enable `scalar_array_key_cast` in your `.phan/config.php`.

+ Warn when using the wrong type of array keys offsets to fetch from an array (E.g. `string` key for `array<int,T>`) (Issue #1390)
+ Infer array key types of `int`, `string`, or `int|string` in `foreach` over arrays. (#1300)
  (Phan's type system doesn't support inferring key types for `iterable` or `Traversable` right now)
+ Support **parsing** PHPDoc array shapes
  (E.g. a function expecting `['field' => 'a string']` can document this as `@param array{field:string}` $options)
  For now, this is converted to generic arrays (Equivalent to `string[]`).

  `[[0, ...], new stdClass]` would have type `array{0:int[], 1:string}`

  - The field value types can be any union type.
  - Field keys are currently limited to keys matching the regex `[-_.a-zA-Z0-9\x7f-\xff]+`. (Identifiers, numbers, '-', and '.')
    Escape mechanisms such as backslashes (e.g. "\x20" for " ") may be supported in the future.
+ Add `PhanTypeMismatchUnpackKey` and `PhanTypeMismatchUnpackValue` to analyze array unpacking operator (also known as splat) (#1384)

  Emit `PhanTypeMismatchUnpackKey` when passing iterables/arrays with invalid keys to the unpacking operator (i.e. `...`).

  Emit `PhanTypeMismatchUnpackValue` when passing values that aren't iterables or arrays to the unpacking operator.
  (See https://secure.php.net/manual/en/migration56.new-features.php#migration56.new-features.splat)
+ When determining the union type of an array literal,
  base it on the union types of **all** of the values (and all of the keys) instead of just the first 5 array elements.
+ When determining the union type of the possible value types of a array literal,
  combine the generic types into a union type instead of simplifying the types to `array`.
  In practical terms, this means that `[1,2,'a']` is seen as `array<int,int|string>`,
  which Phan represents as `array<int,int>|array<int,string>`.

  In the previous Phan release, the union type of `[1,2,'a']` would be represented as `int[]|string[]`,
  which is equivalent to `array<mixed,int>|array<mixed,string>`

  Another example: `[$strKey => new MyClass(), $strKey2 => $unknown]` will be represented as
  `array<string,MyClass>|array<string,mixed>`.
  (If Phan can't infer a type of a key or value, `mixed` gets added to that key or value.)
+ Improve analysis of try/catch/finally blocks (#1408)
  Analyze `catch` blocks with the inferences about the `try` block.
  Analyze a `finally` block with the combined inferences from the `try` and `catch` blocks.
+ Account for side effects of `&&` and `||` operators in expressions, outside of `if`/`assert` statements. (#1415)
  E.g. `$isValid = ($x instanceof MyClass && $x->isValid())` will now consistently check that isValid() exists on MyClass.
+ Improve analysis of expressions within conditionals, such as `if (!($x instanceof MyClass) || $x->method())`
  or `if (!(cond($x) && othercond($x)))`

  (Phan is now aware of the types of the right hand side of `||` and `&&` in more cases)
+ Add a large number of param and return type signatures for internal functions and methods,
  for params and return types that were previously untyped.
  (Imported from docs.php.net's SVN repo)
+ More precise analysis of the return types of `var_export()`, `print_r()`, and `json_decode()` (#1326, #1327)
+ Improve type narrowing from `iterable` to `\Traversable`/`array` (#1427)
  This change affects `is_array()`/`is_object()` checks and their negations.
+ Fix more edge cases which would cause Phan to fail to infer that properties, constants, or methods are inherited. (PR #1440 for issues #311, #1426, #454)

Plugins
+ Fix bugs in `NonBoolBranchPlugin` and `NonBoolInLogicalArithPlugin` (#1413, #1410)
+ **Make UnionType instances immutable.**
  This will affect plugins that used addType/addUnionType/removeType. withType/withUnionType/withoutType should be used instead.
  To modify the type of elements(properties, method return types, parameters, variables, etc),
  plugin authors should use `Element->setUnionType(plugin_modifier_function(Element->getUnionType()))`.

Language server:
+ Add a CLI option `--language-server-analyze-only-on-save` to prevent the client from sending change notifications. (#1325)
  (Only notify the language server when the user saves a document)
  This significantly reduces CPU usage, but clients won't get notifications about issues immediately.

Bug fixes
+ Warn when attempting to call an instance method on an expression with type string (#1314).
+ Fix a bug in `tool/make_stubs` when generating stubs of global functions.
+ Fix some bugs that occurred when Phan resolved inherited class constants in class elements such as properties. (#537 and #454)
+ Emit an issue when a function/method's parameter defaults refer to an undeclared class constant/global constant.

20 Jan 2018, Phan 0.11.1
------------------------

### Ported from Phan 0.10.3

New Features(CLI, Configs)

+ For `--fallback-parser`: Switch to [tolerant-php-parser](https://github.com/Microsoft/tolerant-php-parser)
  as a dependency of the fallback implementation. (#1125)
  This does a better job of generating PHP AST trees when attempting to parse code with a broader range of syntax errors.
  Keep `PHP-Parser` as a dependency for now for parsing strings.

Maintenance
+ Various performance optimizations, including caching of inferred union types to avoid unnecessary recalculation.
+ Make `phan_client` and the vim snippet in `plugins/vim/phansnippet.vim` more compatible with neovim
+ Upgrade felixfbecker/advanced-json-rpc dependency to ^3.0.0 (#1354)
+ Performance improvements.
  Changed the internal representation of union types to no longer require `spl_object_id` or the polyfill.

Bug Fixes
+ Allow `null` to be passed in where a union type of `mixed` was expected.
+ Don't warn when passing `?T` (PHPDoc or real) where the PHPDoc type was `T|null`. (#609, #1090, #1192, #1337)
  This is useful for expressions used for property assignments, return statements, function calls, etc.
+ Fix a few of Phan's signatures for internal functions and methods.

17 Nov 2017, Phan 0.11.0
------------------------

New Features (Analysis of PHP 7.2)
+ Support analyzing the `object` type hint in real function/method signatures. (#995)
+ Allow widening an overriding method's param types in php 7.2 branch (#1256)
  Phan continues warning about `ParamSignatureRealMismatchHasNoParamType` by default, in case a project needs to work with older php releases.
  Add `'allow_method_param_type_widening' => true` if you wish for Phan to stop emitting that issue category.
+ Miscellaneous function signature changes for analysis of PHP 7.2 codebases (#828)

### Ported from Phan 0.10.2

New Features(Analysis)
+ Enable `simplify_ast` by default.
  The new default value should reduce false positives when analyzing conditions of if statements. (#407, #1066)
+ Support less ambiguous `?(T[])` and `(?T)[]` in phpdoc (#1213)
  Note that `(S|T)[]` is **not** supported yet.
+ Support alternate syntax `array<T>` and `array<Key, T>` in phpdoc (PR #1213)
  Note that Phan ignores the provided value of `Key` completely right now (i.e. same as `T[]`); Key types will be supported in Phan 0.10.3.
+ Speed up Phan analysis on small projects, reduce memory usage (Around 0.15 seconds and 15MB)
  This was done by deferring loading the information about internal classes and functions until that information was needed for analysis.
+ Analyze existence and usage of callables passed to (internal and user-defined) function&methods expecting callable. (#1194)
  Analysis will now warn if the referenced function/method of a callable array/string
  (passed to a function/method expecting a callable param) does not exist.

  This change also reduces false positives in dead code detection (Passing in these callable arrays/strings counts as a reference now)
+ Warn if attempting to read/write to an property or constant when the expression is a non-object. (or not a class name, for static elements) (#1268)
+ Split `PhanUnreferencedClosure` out of `PhanUnreferencedFunction`. (Emitted by `--dead-code-detection`)
+ Split `PhanUnreferencedMethod` into `PhanUnreferencedPublicMethod`, `PhanUnreferencedProtectedMethod`, and `PhanUnreferencedPrivateMethod`.
+ Split errors for class constants out of `PhanUnreferencedConst`:
  Add `PhanUnreferencedPublicClassConst`, `PhanUnreferencedProtectedClassConst`, and `PhanUnreferencedPrivateClassConst`.
  `PhanUnreferencedConst` is now exclusively used for global constants.
+ Analyze uses of `compact()` for undefined variables (#1089)
+ Add `PhanParamSuspiciousOrder` to warn about mixing up variable and constant/literal arguments in calls to built in string/regex functions
  (`explode`, `strpos`, `mb_strpos`, `preg_match`, etc.)
+ Preserve the closure's function signature in the inferred return value of `Closure::bind()`. (#869)
+ Support indicating that a reference parameter's input value is unused by writing `@phan-output-reference` on the same line as an `@param` annotation.
  This indicates that Phan should not warn about the passed in type, and should not preserve the passed in type after the call to the function/method.
  (In other words, Phan will analyze a user-defined reference parameter the same way as it would `$matches` in `preg_match($pattern, $string, $matches)`)
  Example usage: `/** @param string $x @phan-output-reference */ function set_x(&$x) { $x = 'result'; }`
+ Make phan infer unreachability from fatal errors such as `trigger_error($message, E_USER_ERROR);` (#1224)
+ Add new issue types for places where an object would be expected:
  `PhanTypeExpectedObjectPropAccess`, `PhanTypeExpectedObjectPropAccessButGotNull`, `PhanTypeExpectedObjectStaticPropAccess`,
  `PhanTypeExpectedObject`, and `PhanTypeExpectedObjectOrClassName
+ Emit more accurate line numbers for phpdoc comments, when warning about phpdoc in doc comments being invalid. (#1294)
  This gives up and uses the element's line number if the phpdoc ends over 10 lines before the start of the element.
+ Work on allowing union types to be part of template types in doc comments,
  as well as types with template syntax.
  (e.g. `array<int|string>` is now equivalent to `int[]|string[]`,
  and `MyClass<T1|T2,T3|T4>` can now be parsed in doc comments)
+ Disambiguate the nullable parameter in output.
  E.g. an array of nullable integers will now be printed in error messages as `(?int)[]`
  A nullable array of integers will continue to be printed in error messages as `?int[]`, and can be specified in PHPDoc as `?(int[])`.

New Features (CLI, Configs)
+ Improve default update rate of `--progress-bar` (Update it every 0.10 seconds)

Bug Fixes
+ Fixes bugs in `PrintfCheckerPlugin`: Alignment goes before width, and objects with __toString() can cast to %s. (#1225)
+ Reduce false positives in analysis of gotos, blocks containing gotos anywhere may do something other than return or throw. (#1222)
+ Fix a crash when a magic method with a return type has the same name as a real method.
+ Allow methods to have weaker PHPdoc types than the overridden method in `PhanParamSignatureMismatch`. (#1253)
  `PhanParamSignatureRealMismatch*` is unaffected, and will continue working the same way in Phan releases analyzing PHP < 7.2.
+ Stop warning about `PhanParamSignatureMismatch`, etc. for private methods. The private methods don't affect each other. (#1250)
+ Properly parse `?self` as a *nullable* instance of the current class in union types (#1264)
+ Stop erroneously warning about inherited constants being unused in subclasses for dead code detection (#1260)
+ For dead code detection, properly track uses of inherited class elements (methods, properties, classes) as uses of the original definition. (#1108)
  Fix the way that uses of private/protected methods from traits were tracked.
  Also, start warning about a subset of issues from interfaces and abstract classes (e.g. unused interface constants)
+ Properly handle `static::class` as a class name in an array callable, or `static::method_name` in a string callable (#1232)
+ Make `@template` tag for [Generic Types](https://github.com/phan/phan/wiki/Generic-Types) case sensitive. (#1243)
+ Fix a bug causing Phan to infer an empty union type (which can cast to any type) for arrays with elements of empty union types. (#1296)

Plugins
+ Make DuplicateArrayKeyPlugin start warning about duplicate values of known global constants and class constants. (#1139)
+ Make DuplicateArrayKeyPlugin start warning about case statements with duplicate values (This resolves constant values the same way as array key checks)
+ Support `'plugins' => ['AlwaysReturnPlugin']` as shorthand for full relative path to a bundled plugin such as AlwaysReturnPlugin.php (#1209)

Maintenance
+ Performance improvements: Phan analysis is 13%-22% faster than 0.10.1, with `simplify_ast` enabled.
+ Used PHP_CodeSniffer to automatically make Phan's source directory adhere closely to PSR-1 and PSR-2, making minor changes to many files.
  (e.g. which line each brace goes on, etc.)
+ Stop tracking references to internal (non user-defined) elements (constants, properties, functions, classes, and methods) during dead code detection.
  (Dead code detection now requires an extra 15MB instead of 17MB for self-analysis)

20 Oct 2017, Phan 0.10.1
------------------------

New Features(Analysis)
+ Support `@return $this` in phpdoc for methods and magic methods.
  (but not elsewhere. E.g. `@param $this $varName` is not supported, use `@param static $varName`) (#634)
+ Check if functions/methods passed to `array_map` and `array_filter` are compatible with their arguments.
  Recursively analyze the functions/methods passed to `array_map`/`array_filter` if no types were provided. (unless quick mode is being used)

New Features (CLI, Configs)

+ Add Language Server Protocol support (Experimental) (#821)
  Compatibility: Unix, Linux (depends on php `pcntl` extension).
  This has the same analysis capabilities provided by [daemon mode](https://github.com/phan/phan/wiki/Using-Phan-Daemon-Mode#using-phan_client-from-an-editor).
  Supporting a standard protocol should make it easier to write extensions supporting Phan in various IDEs.
  See https://github.com/Microsoft/language-server-protocol/blob/master/README.md
+ Add config (`autoload_internal_extension_signatures`) to allow users to specify PHP extensions (modules) used by the analyzed project,
  along with stubs for Phan to use (instead of ReflectionFunction, etc) if the PHP binary used to run Phan doesn't have those extensions enabled. (#627)
  Add a script (`tool/make_stubs`) to output the contents of stubs to use for `autoload_internal_extension_signatures` (#627).
+ By default, automatically restart Phan without xdebug if xdebug is enabled. (#1161)
  If you wish to analyze a project using xdebug's functions, set `autoload_internal_extension_signatures`
  (e.g. `['xdebug' => 'vendor/phan/phan/.phan/internal_stubs/xdebug.phan_php']`)
  If you wish to use xdebug to debug Phan's analysis itself, set and export the environment variable `PHAN_ALLOW_XDEBUG=1`.
+ Improve analysis of return types of `array_pop`, `array_shift`, `current`, `end`, `next`, `prev`, `reset`, `array_map`, `array_filter`, etc.
  See `ArrayReturnTypeOverridePlugin.php.`
  Phan can analyze callables (for `array_map`/`array_filter`) of `Closure` form, as well as strings/2-part arrays that are inlined.
+ Add `--memory-limit` CLI option (e.g. `--memory-limit 500M`). If this option isn't provided, there is no memory limit. (#1148)

Maintenance
+ Document the `--disable-plugins` CLI flag.

Plugins
+ Add a new plugin capability `ReturnTypeOverrideCapability` which can override the return type of functions and methods on a case by case basis.
  (e.g. based on one or more of the argument types or values) (related to #612, #1181)
+ Add a new plugin capability `AnalyzeFunctionCallCapability` which can add logic to analyze calls to a small subset of functions.
  (e.g. based on one or more of the argument types or values) (#1181)
+ Make line numbers more accurate in `DuplicateArrayKeyPlugin`.
+ Add `PregRegexCheckerPlugin` to check for invalid regexes. (uses `AnalyzeFunctionCallCapability`).
  This plugin is able to resolve literals, global constants, and class constants as regexes.
  See [the corresponding section of .phan/plugins/README.md](.phan/plugins/README.md#pregregexcheckerpluginphp)
+ Add `PrintfCheckerPlugin` to check for invalid format strings or incorrect arguments in printf calls. (uses `AnalyzeFunctionCallCapability`)
  This plugin is able to resolve literals, global constants, and class constants as format strings.
  See [the corresponding section of .phan/plugins/README.md](.phan/plugins/README.md#printfcheckerpluginphp)

Bug Fixes
+ Properly check for undeclared classes in arrays within phpdoc `@param`, `@property`, `@method`, `@var`, and `@return` (etc.) types.
  Also, fix a bug in resolving namespaces of generic arrays that are nested 2 or more array levels deep.
+ Fix uncaught TypeError when magic property has the same name as a property. (#1141)
+ Make AlwaysReturnPlugin warn about functions/methods with real nullable return types failing to return a value.
+ Change the behavior of the `-d` flag, make it change the current working directory to the provided directory.
+ Properly set the real param type and return types of internal functions, in rare cases where that exists.
+ Support analyzing the rare case of namespaced internal global functions (e.g. `\ast\parse_code($code, $version)`)
+ Improve analysis of shorthand ternary operator: Remove false/null from cond_expr in `(cond_expr) ?: (false_expr)` (#1186)

24 Sep 2017, Phan 0.10.0
------------------------

New Features(Analysis)
+ Check types of dimensions when using array access syntax (#406, #1093)
  (E.g. for an `array`, check that the array dimension can cast to `int|string`)

New Features (CLI, Configs)
+ Add option `ignore_undeclared_functions_with_known_signatures` which can be set to `false`
  to always warn about global functions Phan has signatures for
  but are unavailable in the current PHP process (and enabled extensions, and the project being analyzed) (#1080)
  The default was/is to not warn, to reduce false positives.
+ Add CLI flag `--use-fallback-parser` (Experimental).  If this flags is provided, then when Phan analyzes a syntactically invalid file,
  it will try again with a parser which tolerates a few types of errors, and analyze the statements that could be parsed.
  Useful in combination with daemon mode.
+ Add `phpdoc_type_mapping` config setting.
  Projects can override this to make Phan ignore or substitute non-standard phpdoc2 types and common typos (#294)
  (E.g. `'phpdoc_type_mapping' => ['the' => '', 'unknown_type' => '', 'number' => 'int|float']`)

Maintenance
+ Increased minimum `ext-ast` version constraint to 0.1.5, switched to AST version 50.
+ Update links to project from github.com/etsy/phan to github.com/phan/phan.
+ Use the native `spl_object_id` function if it is available for the union type implementation.
  This will make phan 10% faster in PHP 7.2.
  (for PHP 7.1, https://github.com/runkit7/runkit_object_id 1.1.0+ also provides a native implementation of `spl_object_id`)
+ Reduce memory usage by around 5% by tracking only the file and lines associated with variables, instead of a full Context object.


Plugins
+ Increased minimum `ext-ast` version constraint to 0.1.5, switched to AST version 50.
  Third party plugins will need to create a different version, Decls were changed into regular Nodes
+ Implement `AnalyzePropertyCapability` and `FinalizeProcessCapability`.
  Make `UnusedSuppressionPlugin` start using `AnalyzePropertyCapability` and `FinalizeProcessCapability`.
  Fix bug where `UnusedSuppressionPlugin` could run before the suppressed issues would be emitted,
  making it falsely emit that suppressions were unused.

Bug Fixes
+ Fix a few incorrect property names for Phan's signatures of internal classes (#1085)
+ Fix bugs in lookup of relative and non-fully qualified class and function names (#1097)
+ Fix a bug affecting analysis of code when `simplify_ast` is true.
+ Fix uncaught NodeException when analyzing complex variables as references (#1116),
  e.g. `function_expecting_reference($$x)`.

15 Aug 2017, Phan 0.9.4
-----------------------

New Features (Analysis)
+ Check (the first 5) elements of returned arrays against the declared return union types, individually (Issue #935)
  (E.g. `/** @return int[] */ function foo() {return [2, "x"]; }` will now warn with `PhanTypeMismatchReturn` about returning `string[]`)
+ Check both sides of ternary conditionals against the declared return union types
  (E.g. `function foo($x) : int {return is_string($x) ? $x : 0; }` will now warn with `PhanTypeMismatchReturn`
  about returning a string).
+ Improved analysis of negations of conditions within ternary conditional operators and else/else if statements. (Issue #538)
  Support analysis of negation of the `||` operator. (E.g. `if (!(is_string($x) || is_int($x))) {...}`)
+ Make phan aware of blocks of code which will unconditionally throw or return. (Issue #308, #817, #996, #956)

  Don't infer variable types from blocks of code which unconditionally throw or return.

  Infer the negation of type assertions from if statements that unconditionally throw/return/break/continue.
  (E.g. `if (!is_string($x)) { return false; } functionUsingX($x);`)

  When checking if a variable is defined by all branches of an if statement, ignore branches which unconditionally throw/return/break/continue.
+ To reduce the false positives from analysis of the negation of type assertions,
  normalize nullable/boolean union types after analyzing code branches (E.g. if/else) affecting the types of those variables.
  (e.g. convert "bool|false|null" to "?bool")
+ Add a new plugin file `AlwaysReturnPlugin`. (Issue #996)
  This will add a stricter check that a function with a non-null return type *unconditionally* returns a value (or explicitly throws, or exit()s).
  Currently, Phan just checks if a function *may* return, or unconditionally throws.
+ Add a new plugin file `UnreachableCodePlugin` (in development).
  This will warn about statements that appear to be unreachable
  (statements occurring after unconditional return/break/throw/return/exit statements)

New Features (CLI, Configs)
+ Add config setting `prefer_narrowed_phpdoc_return_type` (See "New Features (CLI, Configs)),
  which will use only the phpdoc return types for inferences, if they're narrowed.
  This config is enabled by default, and requires `check_docblock_signature_return_type_match` to be enabled.

Bug Fixes
+ Work around notice about COMPILER_HALT_OFFSET on windows.
+ Fixes #462 : Fix type inferences for instanceof for checks with dynamic class names are provided.
  Valid class names are either a string or an instance of the class to check against.
  Warn if the class name is definitely invalid.
+ Fix false positives about undefined variables in isset()/empty() (Issue #1039)
  (Fixes bug introduced in Phan 0.9.3)
+ Fix false positive warnings about accessing protected methods from traits (Issue #1033)
  Act as though the class which used a trait is the place where the method was defined,
  so that method visibility checks work properly.
  Additionally, fix false positive warnings about visibility of method aliases from traits.
+ Warn about instantiation of class with inaccessible constructor (Issue #1043)
+ Fix rare uncaught exceptions (Various)
+ Make issues and plugin issues on properties consistently use suppressions from the plugin doc comment.

Changes In Emitted Issues
+ Improve `InvalidVariableIssetPlugin`. Change the names and messages for issue types.
  Emit `PhanPluginUndeclaredVariableInIsset` and `PhanPluginComplexVariableIsset`
  instead of `PhanUndeclaredVariable`.
  Stop erroneously warning about valid property fetches and checks of fields of superglobals.

0.9.3 Jul 11, 2017
------------------

New Features (Analysis)
+ Automatically inherit `@param` and `@return` types from parent methods.
  This is controlled by the boolean config `inherit_phpdoc_types`, which is true by default.
  `analyze_signature_compatibility` must also be set to true (default is true) for this step to be performed.
+ Better analysis of calls to parent::__construct(). (Issue #852)
+ Warn with `PhanAccessOwnConstructor` if directly invoking self::__construct or static::__construct in some cases (partial).
+ Start analyzing the inside of for/while loops using the loop's condition (Issue #859)
  (Inferences may leak to outside of those loops. `do{} while(cond)` is not specially analyzed yet)
+ Improve analysis of types in expressions within compound conditions (Issue #847)
  (E.g. `if (is_array($x) && fn_expecting_array($x)) {...}`)
+ Evaluate the third part of a for loop with the context after the inner body is evaluated (Issue #477)
+ Emit `PhanUndeclaredVariableDim` if adding an array field to an undeclared variable. (Issue #841)
  Better analyze `list($var['field']) = values`
+ Improve accuracy of `PhanTypeMismatchDeclaredReturn` (Move the check to after parse phase is finished)
+ Enable `check_docblock_signature_return_type_match` and `check_docblock_signature_param_type_match` by default.
  Improve performance of those checks.
  Switch to checking individual types (of the union type) of the phpdoc types and emitting issues for each invalid part.
+ Create `PhanTypeMismatchDeclaredParam` (Move the check to after parse phase is finished)
  Also add config setting `prefer_narrowed_phpdoc_param_type` (See "New Features (CLI, Configs))
  This config is enabled by default.

  Also create `PhanTypeMismatchDeclaredParamNullable` when params such as `function foo(string $x = null)`
  are documented as the narrowed forms `@param null $x` or `@param string $x`.
  Those should be changed to either `string|null` or `?string`.
+ Detect undeclared return types at point of declaration, and emit `PhanUndeclaredTypeReturnType` (Issue #835)
+ Create `PhanParamSignaturePHPDocMismatch*` issue types, for mismatches between `@method` and real signature/other `@method` tag.
+ Create `PhanAccessWrongInheritanceCategory*` issue types to warn about classes extending a trait/interface instead of class, etc. (#873)
+ Create `PhanExtendsFinalClass*` issue types to warn about classes extending from final classes.
+ Create `PhanAccessOverridesFinalMethod*` issue types to warn about methods overriding final methods.
+ Create `PhanTypeMagicVoidWithReturn` to warn if `void` methods such as `__construct`, `__set`, etc return a value that would be ignored. (Issue #913)
+ Add check for `PhanTypeMissingReturn` within closures. Properly emit `PhanTypeMissingReturn` in functions/methods containing closures. (Issue #599)
+ Improved checking for `PhanUndeclaredVariable` in array keys and conditional conditions. (Issue #912)
+ Improved warnings and inferences about internal function references for functions such as `sort`, `preg_match` (Issue #871, #958)
  Phan is now aware of many internal functions which normally ignore the original values of references passed in (E.g. `preg_match`)
+ Properly when code attempts to access static/non-static properties as if they were non-static/static. (Issue #936)
+ Create `PhanCommentOverrideOnNonOverrideMethod` and `PhanCommentOverrideOnNonOverrideConstant`. (Issue #926)
  These issue types will be emitted if `@override` is part of doc comment of a method or class constant which doesn't override or implement anything.
  (`@Override` and `@phan-override` can also be used as aliases of `@override`. `@override` is not currently part of any phpdoc standard.)
+ Add `@phan-closure-scope`, which can be used to annotate closure definitions with the namespaced class it will be bound to (Issue #309, #590, #790)
  (E.g. if the intent was that Closure->bindTo or Closure->bind would be called to bind it to `\MyNS\MyClass` (or an instance of that class),
  then a closure could be declared as `/** @phan-closure-scope \MyNS\MyClass */ function() { $this->somePrivateMyClassMethod(); }`
+ Add `Closure` as a first class type, (Previously, closures were treated as `callable` in some places) (Issue #978)

New Features (CLI, Configs)
+ Create `check_docblock_signature_param_type_match` (similar to `check_docblock_signature_return_type_match`) config setting
  to enable warning if phpdoc types are incompatible with the real types. True(enabled) by default.

  Create `prefer_narrowed_phpdoc_param_type` config setting (True by default, requires `check_docblock_signature_return_type_match` to be enabled).
  When it is true, Phan will analyze each function using the phpdoc param types instead of the provided signature types
  if the possible phpdoc types are narrower and compatible with the signature.
  (E.g. indicate that subclasses are expected over base classes, indicate that non-nullable is expected instead of nullable)
  This affects analysis both inside and outside the method.

  Aside: Phan currently defaults to preferring phpdoc type over real return type, and emits `PhanTypeMismatchDeclaredReturn` if the two are incompatible.
+ Create `enable_class_alias_support` config setting (disabled by default), which enables analyzing basic usage of class_alias. (Issue #586)
  Set it to true to enable it.
  NOTE: this is still experimental.
+ Warn to stderr about running Phan analysis with XDebug (Issue #116)
  The warning can be disabled by the Phan config setting `skip_slow_php_options_warning` to true.
+ Add a config setting 'scalar_implicit_partial' to allow moving away from 'scalar_implicit_cast' (Issue #541)
  This allows users to list out (and gradually remove) permitted scalar type casts.
+ Add `null_casts_as_array` and `array_casts_as_null` settings, which can be used while migrating away from `null_casts_as_any_type`.
  These will be checked if one of the types has a union type of `null`, as well as when checking if a nullable array can cast to a regular array.

Plugins

+ Redesign plugin system to be more efficient. (Issue #600)
  New plugins should extend `\Phan\PluginV2` and implement the interfaces for capabilities they need to have,
  such as `\Phan\PluginV2\AnalyzeClassCapability`.
  In the new plugin system, plugins will only be run when they need to (Phan no longer needs to invoke an empty method body).
  Old subclasses of `\Phan\Plugin\PluginImplementation` will continue to work, but will be less efficient.

Maintenance
+ Reduce memory usage by around 15% by using a more efficient representation of union types (PR #729).
  The optional extension https://github.com/runkit7/runkit_object_id can be installed to boost performance by around 10%.
+ Check method signatures compatibility against all overridden methods (e.g. interfaces with the same methods), not just the first ones (Issue #925)

Bug Fixes
+ Work around known bugs in current releases of two PECL extensions (Issue #888, #889)
+ Fix typo - Change `PhanParamSignatureRealMismatch` to `PhanParamSignatureRealMismatchReturnType`
+ Consistently exit with non-zero exit code if there are multiple processes, and any process failed to return valid results. (Issue #868)
+ Fixes #986 : PhanUndeclaredVariable used to fail to be emitted in some deeply nested expressions, such as `return $undefVar . 'suffix';`
+ Make Phan infer the return types of closures, both for closures invoked inline and closures declared then invoked later (Issue #564)
+ Phan now correctly analyze global functions for mismatches of phpdoc types and real parameter types.
  Previously, it wouldn't emit warnings for global functions, only for methods.
+ Don't add `mixed` to inferred union types of properties which already have non-empty phpdoc types. (Issue #512)
  mixed would just result in Phan failing to emit any types of issues.
+ When `simplify_ast` is true, simplify the ASTs parsed in the parse mode as well.
  Makes analysis consistent when `quick_mode` is false (AST nodes from the parse phase would also be used in the analysis phase)
+ Don't emit PhanTypeNonVarPassByRef on arguments that are function/method calls returning references. (Issue #236)
+ Emit PhanContextNotObject more reliably when not in class scope.

Backwards Incompatible Changes
+ Fix categories of some issue types, renumber error ids for the pylint error formatter to be unique and consistent.

0.9.2 Jun 13, 2017
------------------

New Features (Analysis)
+ Add `PhanParamSignatureRealMismatch*` (e.g. `ParamSignatureRealMismatchTooManyRequiredParameters`),
  which ignores phpdoc types and imitates PHP's inheritance warning/error checks as closely as possible. (Issue #374)
  This has a much lower rate of false positives than `PhanParamSignatureMismatch`, which is based on Liskov Substitution Principle and also accounts for phpdoc types.
  (`PhanParamSignatureMismatch` continues to exist)
+ Create `PhanUndeclaredStaticProperty` (Issue #610)
  This is of higher severity than PhanUndeclaredProperty, because PHP 7 throws an Error.
  Also add `PhanAccessPropertyStaticAsNonStatic`
+ Supports magic instance/static `@method` annotations. (Issue #467)
  This is enabled by default.
+ Change the behavior of non-quick recursion (Affects emitted issues in large projects).
  Improve performance of non-quick analysis by checking for redundant analysis steps
  (E.g. calls from two different places passing the same union types for each parameter),
  continuing to recurse when passing by reference.
+ Support for checking for misuses of "@internal" annotations. Phan assumes this means it is internal to a namespace. (Issue #353)
  This checks properties, methods, class constants, and classes.
  (Adds `PhanAccessConstantInternal`, `PhanAccessClassInternal`, `PhanAccessClassConstantInternal`, `PhanAccessPropertyInternal`, `PhanAccessMethodInternal`)
  (The implementation may change)
+ Make conditionals such as `is_string` start applying to the condition in ternary operators (`$a ? $b : $c`)
+ Treat `resource`, `object`, and `mixed` as native types only when they occur in phpdoc.
  Outside of phpdoc (e.g. `$x instanceof resource`), analyze those names as if they were class names.
+ Emit low severity issues if Phan can't extract types from phpdoc,
  the phpdoc `@param` is out of sync with the code,
  or if the phpdoc annotation doesn't apply to an element type (Issue #778)
+ Allow inferring the type of variables from `===` conditionals such as `if ($x === true)`
+ Add issue type for non-abstract classes containing abstract methods from itself or its ancestors
  (`PhanClassContainsAbstractMethod`, `PhanClassContainsAbstractMethodInternal`)
+ Partial support for handling trait adaptations (`as`/`insteadof`) when using traits (Issue #312)
+ Start checking if uses of private/protected class methods *defined in a trait* are visible outside of that class.
  Before, Phan would always assume they were visible, to reduce false positives.
+ If Phan has inferred/been provided generic array types for a variable (e.g. `int[]`),
  then analysis of the code within `if (is_array($x))` will act as though the type is `int[]`.
  The checks `is_object` and `is_scalar` now also preserve known sub-types of the group of types.
  (If Phan isn't aware of any sub-types, it will infer the generic version, e.g. `object`)
+ Start checking if unanalyzable variable accesses such as `$$x` are very likely to be invalid or typos (e.g. $x is an object or array or null)
  Emit `PhanTypeSuspiciousIndirectVariable` if those are seen. (PR #809)
+ Add partial support for inferring the union types of the results of expressions such as `$x ^= 5` (e.g. in `foo($x ^= 5)`) (PR #809)
+ Thoroughly analyze the methods declared within traits,
  using only the information available within the trait. (Issue #800, PR #815)
  If new emitted issues are seen, users can (1) add abstract methods to traits, (2) add `@method` annotations, or (3) add `@suppress` annotations.

New Features (CLI, Configs)
+ (Linux/Unix only) Add Experimental Phan Daemon mode (PR #563 for Issue #22), which allows phan to run in the background, and accept TCP requests to analyze single files.
  (The implementation currently requires the `pcntl` extension, which does not in Windows)
  Server usage: `path/to/phan --daemonize-tcp-port 4846` (In the root directory of the project being analyzed)
  Client usage: `path/to/phan_client --daemonize-tcp-port 4846 -l src/file1.php [ -l src/file2.php ]`
+ Add `--color` CLI flag, with rudimentary unix terminal coloring for the plain text output formatter. (Issue #363)
  Color schemes are customizable with `color_scheme`, in the config file.
+ Add the `exclude_file_regex` config to exclude file paths based on a regular expression (e.g. tests or example files mixed with the codebase) (#635)
  The regular expression is run against the relative path within the project.
+ Add `--dump-parsed-file-list` option to print files which Phan would parse.
+ Add experimental `simplify_ast` config, to simplify the AST into a form which improves Phan's type inference.
  (E.g. handles some variable declarations within `if ()` statements.
   Infers that $x is a string for constructs such as `if (!is_string($x)) {return;} function_using_x($x);`)
  This is slow, and disabled by default.
+ Add `--include-analysis-file-list` option to define files that will be included in static analysis, to the exclusion of others.
+ Start emitting `PhanDeprecatedFunctionInternal` if an internal (to PHP) function/method is deprecated.
  (Phan emits `PhanUndeclaredFunction` if a function/method was removed; Functions deprecated in PHP 5.x were removed in 7.0)

Maintenance
+ Update function signature map to analyze `iterable` and `is_iterable` from php 7.1
+ Improve type inferences on functions with nullable default values.
+ Update miscellaneous new functions in php 7.1 standard library (e.g. `getenv`)

Bug Fixes
- Fix PhanTypeMismatchArgument, etc. for uses of `new static()`, static::CONST, etc in a method. (Issue #632)
- Fix uncaught exception when conditional node is a scalar (Issue #613)
- Existence of __get() no longer affects analyzing static properties. (Issue #610)
- Phan can now detect the declaration of constants relative to a `use`d namespace (Issue #509)
- Phan can now detect the declaration of functions relative to a `use`d namespace (Issue #510)
- Fix a bug where the JSON output printer accidentally escaped some output ("<"), causing invalid JSON.
- Fix a bug where a print/echo/method call erroneously marked methods/functions as having a return value. (Issue #811)
- Improve analysis of SimpleXMLElement (Issues #542, #539)
- Fix crash handling trait use aliases which change only the method's visibility (Issue #861)

Backwards Incompatible Changes
- Declarations of user-defined constants are now consistently
  analyzed in a case sensitive way.
  This may affect projects using `define(name, value, case_insensitive = true)`.
  Change the code being analyzed to exactly match the constant name in define())

0.9.1 Mar 15, 2017
------------------

New Features (Analysis)
+ Conditions in `if(cond(A) && expr(A))` (e.g. `instanceof`, `is_string`, etc) now affect analysis of right hand side of `&&` (PR #540)
+ Add `PhanDeprecatedInterface` and `PhanDeprecatedTrait`, similar to `PhanDeprecatedClass`
+ Supports magic `@property` annotations, with aliases `@property-read` and @property-write`. (Issue #386)
  This is enabled by default.
  Also adds `@phan-forbid-undeclared-magic-properties` annotation,
  which will make Phan warn about undeclared properties if no real property or `@property` annotation exists.

New Features (CLI, Configs)
+ Add `--version` CLI flag
+ Move some rare CLI options from `--help` into `--extended-help`

Maintenance
+ Improved stability of analyzing phpdoc and real nullable types (Issue #567)
+ Fix type signatures Phan has for some internal methods.
+ Improve CLI `--progress-bar` tracking by printing 0% immediately.
+ Add Developer Certificate of Origin

Bug Fixes
+ Fix uncaught issue exception analyzing class constants (Issue #551)
+ Fix group use in ASTs
+ Fix false positives checking if native types can cast to/from nullable native types (Issue #567, #582)
+ Exit with non-zero exit code if an invalid CLI argument is passed to Phan

Backwards Incompatible Changes
+ Change the way that parameter's default values affect type inferences.
  (May now add to the union type or ignore default values. Used to always add the default value types)
  Add `@param` types if you encounter new issues.
  This was done to avoid false positives in cases such as `function foo($maybeArray = false)`
+ Increase minimum `ext-ast` version constraint to 0.1.4

0.9.0 Feb 21, 2017
------------------

The 0.9.x versions will be tracking syntax from PHP versions 7.1.x and is runnable on PHP 7.1+.
Please use version 0.8.x if you're using a version of PHP < 7.1.

New Features (Analysis)
+ Support php 7.1 class constant visibility
+ Support variadic phpdoc in `@param`, e.g. `@param string ...$args`
  Avoid ambiguity by emitting `PhanTypeMismatchVariadicComment` and `PhanTypeMismatchVariadicParam`.
+ Initial support for php 7.1 nullable types and void, both in phpdoc and real parameters.
+ Initial support for php 7.1 `iterable` type
+ Both conditions from `if(cond(A) && cond(B))` (e.g. `instanceof`, `is_string`, etc.) now affect analysis of the if element's block (PR #540)
+ Apply conditionals such as `is_string` to type guards in ternary operators (Issue #465)
+ Allow certain checks for removing null from Phan's inferred types, reducing false positives (E.g. `if(!is_null($x) && $x->method())`) (#518)
+ Incomplete support for specifying the class scope in which a closure will be used/bound  (#309)
+ Support `@return self` in class context

New Features (CLI, Configs)
+ Introduce `check_docblock_signature_return_type_match` config (slow, disabled by default)
  (Checks if the phpdoc types match up with declared return types)

Maintenance
+ Add Code of Conduct
+ Fix type signatures for some internal methods and internal class properties.

Bug Fixes
+ Allow asserting `object` is a specific object type without warning (Issue #516)
+ Fix bugs in analysis of varargs within a function(Issue #516)
+ Treat null defaults in functions and methods the same way (Issue #508)
  In both, add null defaults to the UnionType only if there's already another type.
  In both, add non-null defaults to the UnionType (Contains `mixed` if there weren't any explicit types)
+ Specially handle phpdoc type aliases such as `boolean` only in phpdoc (Issue #471)
  (Outside of phpdoc, it refers to a class with the name `boolean`)
+ Add some internal classes other than `stdClass` which are allowed to have dynamic, undeclared properties (Issue #433)
+ Fix assertion errors when passing references by reference (Issue #500)

Backwards Incompatible Changes
+ Requires newer `ext-ast` version (Must support version 35).

0.8.3 Jan 26, 2017
------------------

The 0.8.x versions will be tracking syntax from PHP versions 7.0.x and is runnable on PHP 7.0+.
Please use version 0.8.x if you're using a version of PHP < 7.1.
For best results, run version 0.8.x with PHP 7.0 if you are analyzing a codebase which normally runs on php <= 7.0
(If php 7.1 is used, Phan will think that some new classes, methods, and functions exist or have different parameter lists because it gets this info from `Reflection`)

???
