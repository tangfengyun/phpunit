<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Annotation;

use PharIo\Version\VersionConstraintParser;
use PHPUnit\Framework\InvalidDataProviderException;
use PHPUnit\Framework\SkippedTestError;
use PHPUnit\Framework\Warning;
use PHPUnit\Util\Exception;

/**
 * This is an abstraction around a PHPUnit-specific docBlock,
 * allowing us to ask meaningful questions about a specific
 * reflection symbol.
 *
 * @internal This class is part of PHPUnit internals, an not intended
 *           for downstream usage
 *
 * @psalm-immutable
 */
final class DocBlock
{
    public const REGEX_DATA_PROVIDER = '/@dataProvider\s+([a-zA-Z0-9._:-\\\\x7f-\xff]+)/';

    private const REGEX_REQUIRES_VERSION = '/@requires\s+(?P<name>PHP(?:Unit)?)\s+(?P<operator>[<>=!]{0,2})\s*(?P<version>[\d\.-]+(dev|(RC|alpha|beta)[\d\.])?)[ \t]*\r?$/m';

    private const REGEX_REQUIRES_VERSION_CONSTRAINT = '/@requires\s+(?P<name>PHP(?:Unit)?)\s+(?P<constraint>[\d\t \-.|~^]+)[ \t]*\r?$/m';

    private const REGEX_REQUIRES_OS = '/@requires\s+(?P<name>OS(?:FAMILY)?)\s+(?P<value>.+?)[ \t]*\r?$/m';

    private const REGEX_REQUIRES_SETTING = '/@requires\s+(?P<name>setting)\s+(?P<setting>([^ ]+?))\s*(?P<value>[\w\.-]+[\w\.]?)?[ \t]*\r?$/m';

    private const REGEX_REQUIRES = '/@requires\s+(?P<name>function|extension)\s+(?P<value>([^\s<>=!]+))\s*(?P<operator>[<>=!]{0,2})\s*(?P<version>[\d\.-]+[\d\.]?)?[ \t]*\r?$/m';

    private const REGEX_TEST_WITH = '/@testWith\s+/';

    private const REGEX_EXPECTED_EXCEPTION = '(@expectedException\s+([:.\w\\\\x7f-\xff]+)(?:[\t ]+(\S*))?(?:[\t ]+(\S*))?\s*$)m';

    /** @var \ReflectionClass|\ReflectionFunctionAbstract */
    private $reflector;

    private function __construct()
    {
    }

    public static function ofClass(\ReflectionClass $class) : self
    {
        $instance = new self();

        $instance->reflector = $class;

        return $instance;
    }

    public static function ofFunction(\ReflectionFunctionAbstract $function) : self
    {
        $instance = new self();

        $instance->reflector = $function;

        return $instance;
    }

    // @TODO accurately document returned type here
    public function requirements() : array
    {
        $docComment = (string) $this->reflector->getDocComment();
        $offset     = $this->reflector->getStartLine();
        $requires   = [
            '__OFFSET' => [
                '__FILE' => \realpath($this->reflector->getFileName()),
            ],
        ];

        // Split docblock into lines and rewind offset to start of docblock
        $lines  = \preg_split('/\r\n|\r|\n/', $docComment);
        $offset -= \count($lines);

        foreach ($lines as $line) {
            if (\preg_match(self::REGEX_REQUIRES_OS, $line, $matches)) {
                $requires[$matches['name']]             = $matches['value'];
                $requires['__OFFSET'][$matches['name']] = $offset;
            }

            if (\preg_match(self::REGEX_REQUIRES_VERSION, $line, $matches)) {
                $requires[$matches['name']]             = [
                    'version'  => $matches['version'],
                    'operator' => $matches['operator'],
                ];
                $requires['__OFFSET'][$matches['name']] = $offset;
            }

            if (\preg_match(self::REGEX_REQUIRES_VERSION_CONSTRAINT, $line, $matches)) {
                if (! empty($requires[$matches['name']])) {
                    $offset++;

                    continue;
                }

                try {
                    $versionConstraintParser = new VersionConstraintParser;

                    $requires[$matches['name'] . '_constraint']             = [
                        'constraint' => $versionConstraintParser->parse(\trim($matches['constraint'])),
                    ];
                    $requires['__OFFSET'][$matches['name'] . '_constraint'] = $offset;
                } catch (\PharIo\Version\Exception $e) {
                    throw new Warning($e->getMessage(), $e->getCode(), $e);
                }
            }

            if (\preg_match(self::REGEX_REQUIRES_SETTING, $line, $matches)) {
                if (! isset($requires['setting'])) {
                    $requires['setting'] = [];
                }
                $requires['setting'][$matches['setting']]                 = $matches['value'];
                $requires['__OFFSET']['__SETTING_' . $matches['setting']] = $offset;
            }

            if (\preg_match(self::REGEX_REQUIRES, $line, $matches)) {
                $name = $matches['name'] . 's';

                if (! isset($requires[$name])) {
                    $requires[$name] = [];
                }

                $requires[$name][]                                                = $matches['value'];
                $requires['__OFFSET'][$matches['name'] . '_' . $matches['value']] = $offset;

                if ($name === 'extensions' && ! empty($matches['version'])) {
                    $requires['extension_versions'][$matches['value']] = [
                        'version'  => $matches['version'],
                        'operator' => $matches['operator'],
                    ];
                }
            }

            $offset++;
        }

        return $requires;
    }

    /**
     * @return bool|array
     *
     * @psalm-return false|array{
     *   class: class-string,
     *   code: int|null,
     *   message: string,
     *   message_regex: string
     * }
     */
    public function expectedException()
    {
        $docComment = (string) \substr((string) $this->reflector->getDocComment(), 3, -2);

        if (1 !== \preg_match(self::REGEX_EXPECTED_EXCEPTION, $docComment, $matches)) {
            return false;
        }

        $annotations   = $this->parseSymbolAnnotations();
        $class         = $matches[1];
        $code          = null;
        $message       = '';
        $messageRegExp = '';

        if (isset($matches[2])) {
            $message = \trim($matches[2]);
        } elseif (isset($annotations['expectedExceptionMessage'])) {
            $message = $this->parseAnnotationContent($annotations['expectedExceptionMessage'][0]);
        }

        if (isset($annotations['expectedExceptionMessageRegExp'])) {
            $messageRegExp = $this->parseAnnotationContent($annotations['expectedExceptionMessageRegExp'][0]);
        }

        if (isset($matches[3])) {
            $code = $matches[3];
        } elseif (isset($annotations['expectedExceptionCode'])) {
            $code = $this->parseAnnotationContent($annotations['expectedExceptionCode'][0]);
        }

        if (\is_numeric($code)) {
            $code = (int) $code;
        } elseif (\is_string($code) && \defined($code)) {
            $code = (int) \constant($code);
        }

        return [
            'class'         => $class,
            'code'          => $code,
            'message'       => $message,
            'message_regex' => $messageRegExp,
        ];
    }

    /**
     * Returns the provided data for a method.
     *
     * @throws Exception
     */
    public function getProvidedData() : ?array
    {
        $docComment = (string) $this->reflector->getDocComment();
        $data       = $this->getDataFromDataProviderAnnotation($docComment)
            ?? $this->getDataFromTestWithAnnotation($docComment);

        if ($data === null) {
            return null;
        }

        if ($data === []) {
            throw new SkippedTestError;
        }

        foreach ($data as $key => $value) {
            if (!\is_array($value)) {
                throw new Exception(
                    \sprintf(
                        'Data set %s is invalid.',
                        \is_int($key) ? '#' . $key : '"' . $key . '"'
                    )
                );
            }
        }

        return $data;
    }

    /**
     * @psalm-return array<string, array{line: int, value: string}>
     */
    public function getInlineAnnotations() : array
    {
        $code        = \file($this->reflector->getFileName());
        $lineNumber  = $this->reflector->getStartLine();
        $startLine   = $this->reflector->getStartLine() - 1;
        $endLine     = $this->reflector->getEndLine() - 1;
        $codeLines   = \array_slice($code, $startLine, $endLine - $startLine + 1);
        $annotations = [];

        foreach ($codeLines as $line) {
            if (\preg_match('#/\*\*?\s*@(?P<name>[A-Za-z_-]+)(?:[ \t]+(?P<value>.*?))?[ \t]*\r?\*/$#m', $line, $matches)) {
                $annotations[\strtolower($matches['name'])] = [
                    'line'  => $lineNumber,
                    'value' => $matches['value'],
                ];
            }

            $lineNumber++;
        }

        return $annotations;
    }

    private function parseSymbolAnnotations() : array
    {
        $annotations = [];

        if ($this->reflector instanceof \ReflectionClass) {
            $annotations = \array_merge(
                $annotations,
                ...array_map(function (\ReflectionClass $trait) : array {
                    return $this->parseDocBlock((string) $trait->getDocComment());
                }, $this->reflector->getTraits())
            );
        }

        return \array_merge(
            $annotations,
            $this->parseDocBlock((string) $this->reflector->getDocComment())
        );
    }

    /** @return array<string, array<int, string>> */
    private function parseDocBlock(string $docBlock) : array
    {
        // Strip away the docblock header and footer to ease parsing of one line annotations
        $docBlock    = (string) \substr($docBlock, 3, -2);
        $annotations = [];

        if (\preg_match_all('/@(?P<name>[A-Za-z_-]+)(?:[ \t]+(?P<value>.*?))?[ \t]*\r?$/m', $docBlock, $matches)) {
            $numMatches = \count($matches[0]);

            for ($i = 0; $i < $numMatches; ++$i) {
                $annotations[$matches['name'][$i]][] = (string) $matches['value'][$i];
            }
        }

        return $annotations;
    }

    /**
     * Parse annotation content to use constant/class constant values
     *
     * Constants are specified using a starting '@'. For example: @ClassName::CONST_NAME
     *
     * If the constant is not found the string is used as is to ensure maximum BC.
     */
    private function parseAnnotationContent(string $message) : string
    {
        if (\defined($message)
            && (
                \strpos($message, '::') !== false
                && \substr_count($message, '::') + 1 === 2
            )
        ) {
            return \constant($message);
        }

        return $message;
    }

    private function getDataFromDataProviderAnnotation(string $docComment): ?iterable
    {
        $methodName = null;
        $className  = null;

        if ($this->reflector instanceof \ReflectionMethod) {
            $methodName = $this->reflector->getName();
            $className = $this->reflector->getDeclaringClass()
                ->getName();
        }

        if ($this->reflector instanceof \ReflectionClass) {
            $className = $this->reflector->getName();
        }

        if (! \preg_match_all(self::REGEX_DATA_PROVIDER, $docComment, $matches)) {
            return null;
        }

        $result = [];

        foreach ($matches[1] as $match) {
            $dataProviderMethodNameNamespace = \explode('\\', $match);
            $leaf                            = \explode('::', \array_pop($dataProviderMethodNameNamespace));
            $dataProviderMethodName          = \array_pop($leaf);

            if (empty($dataProviderMethodNameNamespace)) {
                $dataProviderMethodNameNamespace = '';
            } else {
                $dataProviderMethodNameNamespace = \implode('\\', $dataProviderMethodNameNamespace) . '\\';
            }

            if (empty($leaf)) {
                $dataProviderClassName = $className;
            } else {
                $dataProviderClassName = $dataProviderMethodNameNamespace . \array_pop($leaf);
            }

            try {
                $dataProviderClass = new \ReflectionClass($dataProviderClassName);

                $dataProviderMethod = $dataProviderClass->getMethod(
                    $dataProviderMethodName
                );
            } catch (\ReflectionException $e) {
                throw new Exception(
                    $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }

            if ($dataProviderMethod->isStatic()) {
                $object = null;
            } else {
                $object = $dataProviderClass->newInstance();
            }

            if ($dataProviderMethod->getNumberOfParameters() === 0) {
                $data = $dataProviderMethod->invoke($object);
            } else {
                $data = $dataProviderMethod->invoke($object, $methodName);
            }

            if ($data instanceof \Traversable) {
                $origData = $data;
                $data     = [];

                foreach ($origData as $key => $value) {
                    if (\is_int($key)) {
                        $data[] = $value;
                    } elseif (\array_key_exists($key, $data)) {
                        throw new InvalidDataProviderException(
                            \sprintf(
                                'The key "%s" has already been defined in the data provider "%s".',
                                $key,
                                $match
                            )
                        );
                    } else {
                        $data[$key] = $value;
                    }
                }
            }

            if (\is_array($data)) {
                $result = \array_merge($result, $data);
            }
        }

        return $result;
    }

    /**
     * @throws Exception
     */
    private function getDataFromTestWithAnnotation(string $docComment): ?array
    {
        $docComment = $this->cleanUpMultiLineAnnotation($docComment);

        if (! \preg_match(self::REGEX_TEST_WITH, $docComment, $matches, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $offset            = \strlen($matches[0][0]) + $matches[0][1];
        $annotationContent = \substr($docComment, $offset);
        $data              = [];

        foreach (\explode("\n", $annotationContent) as $candidateRow) {
            $candidateRow = \trim($candidateRow);

            if ($candidateRow[0] !== '[') {
                break;
            }

            $dataSet = \json_decode($candidateRow, true);

            if (\json_last_error() !== \JSON_ERROR_NONE) {
                throw new Exception(
                    'The data set for the @testWith annotation cannot be parsed: ' . \json_last_error_msg()
                );
            }

            $data[] = $dataSet;
        }

        if (!$data) {
            throw new Exception('The data set for the @testWith annotation cannot be parsed.');
        }

        return $data;
    }

    private function cleanUpMultiLineAnnotation(string $docComment): string
    {
        //removing initial '   * ' for docComment
        $docComment = \str_replace("\r\n", "\n", $docComment);
        $docComment = \preg_replace('/' . '\n' . '\s*' . '\*' . '\s?' . '/', "\n", $docComment);
        $docComment = (string) \substr($docComment, 0, -1);

        return \rtrim($docComment, "\n");
    }
}