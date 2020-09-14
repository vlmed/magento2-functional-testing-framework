<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\FunctionalTestingFramework\Test\Handlers;

use Magento\FunctionalTestingFramework\Config\MftfApplicationConfig;
use Magento\FunctionalTestingFramework\Exceptions\FastFailException;
use Magento\FunctionalTestingFramework\Exceptions\TestFrameworkException;
use Magento\FunctionalTestingFramework\Exceptions\TestReferenceException;
use Magento\FunctionalTestingFramework\Exceptions\XmlException;
use Magento\FunctionalTestingFramework\ObjectManager\ObjectHandlerInterface;
use Magento\FunctionalTestingFramework\ObjectManagerFactory;
use Magento\FunctionalTestingFramework\Test\Objects\TestObject;
use Magento\FunctionalTestingFramework\Test\Parsers\TestDataParser;
use Magento\FunctionalTestingFramework\Test\Util\ObjectExtensionUtil;
use Magento\FunctionalTestingFramework\Test\Util\TestObjectExtractor;
use Magento\FunctionalTestingFramework\Util\GenerationErrorHandler;
use Magento\FunctionalTestingFramework\Util\Logger\LoggingUtil;
use Magento\FunctionalTestingFramework\Util\Validation\NameValidationUtil;

/**
 * Class TestObjectHandler
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TestObjectHandler implements ObjectHandlerInterface
{
    const XML_ROOT = 'tests';
    const TEST_FILENAME_ATTRIBUTE = 'filename';

    /**
     * Test Object Handler
     *
     * @var TestObjectHandler
     */
    private static $testObjectHandler;

    /**
     * Array contains all test objects indexed by name
     *
     * @var TestObject[] $tests
     */
    private $tests = [];

    /**
     * Instance of ObjectExtensionUtil class
     *
     * @var ObjectExtensionUtil
     */
    private $extendUtil;

    /**
     * Singleton method to return TestObjectHandler.
     *
     * @return TestObjectHandler
     * @throws FastFailException
     * @throws TestFrameworkException
     */
    public static function getInstance($validateAnnotations = true)
    {
        if (!self::$testObjectHandler) {
            self::$testObjectHandler = new TestObjectHandler();
            self::$testObjectHandler->initTestData($validateAnnotations);
        }

        return self::$testObjectHandler;
    }

    /**
     * TestObjectHandler constructor.
     */
    private function __construct()
    {
        $this->extendUtil = new ObjectExtensionUtil();
    }

    /**
     * Takes a test name and returns the corresponding test.
     *
     * @param string $testName
     * @return TestObject
     * @throws TestReferenceException
     */
    public function getObject($testName)
    {
        if (!array_key_exists($testName, $this->tests)) {
            throw new TestReferenceException("Test ${testName} not defined in xml.");
        }
        $testObject = $this->tests[$testName];

        return $this->extendTest($testObject);
    }

    /**
     * Returns all tests parsed from xml indexed by testName.
     *
     * @return array
     * @throws FastFailException
     * @throws TestFrameworkException
     */
    public function getAllObjects()
    {
        $testObjects = [];
        foreach ($this->tests as $testName => $test) {
            try {
                $testObjects[$testName] = $this->extendTest($test);
            } catch (FastFailException $exception) {
                throw $exception;
            } catch (\Exception $exception) {
                LoggingUtil::getInstance()->getLogger(self::class)->error(
                    "Unable to create test " . $testName . "\n" . $exception->getMessage()
                );
                if (MftfApplicationConfig::getConfig()->getPhase() == MftfApplicationConfig::GENERATION_PHASE) {
                    print("ERROR: Unable to create test " . $testName . "\n" . $exception->getMessage());
                }
                if (MftfApplicationConfig::getConfig()->getPhase() != MftfApplicationConfig::EXECUTION_PHASE) {
                    GenerationErrorHandler::getInstance()->addError(
                        'test',
                        $testName,
                        self::class . ': Unable to create test ' . $exception->getMessage()
                    );
                }
            }
        }
        return $testObjects;
    }

    /**
     * Returns tests tagged with the group name passed to the method.
     *
     * @param string $groupName
     * @return TestObject[]
     * @throws FastFailException
     * @throws TestFrameworkException
     */
    public function getTestsByGroup($groupName)
    {
        $relevantTests = [];
        foreach ($this->tests as $test) {
            try {
                /** @var TestObject $test */
                if (in_array($groupName, $test->getAnnotationByName('group'))) {
                    $relevantTests[$test->getName()] = $this->extendTest($test);
                }
            } catch (FastFailException $exception) {
                throw $exception;
            } catch (\Exception $exception) {
                $message = "Unable to reference test "
                    . $test->getName()
                    . " for group {$groupName}\n"
                    . $exception->getMessage();
                LoggingUtil::getInstance()->getLogger(self::class)->error($message);
                if (MftfApplicationConfig::getConfig()->getPhase() == MftfApplicationConfig::GENERATION_PHASE) {
                    print('ERROR: ' . $message);
                }
                if (MftfApplicationConfig::getConfig()->getPhase() != MftfApplicationConfig::EXECUTION_PHASE) {
                    GenerationErrorHandler::getInstance()->addError(
                        'test',
                        $test->getName(),
                        self::class . ': ' . $message
                    );
                }
            }
        }

        return $relevantTests;
    }

    /**
     * Sanitize test objects
     *
     * @param array $testsToRemove
     * @return void
     */
    public function sanitizeTests($testsToRemove)
    {
        foreach ($testsToRemove as $name) {
            unset($this->tests[$name]);
            LoggingUtil::getInstance()->getLogger(self::class)->error(
                "Removed invalid test object {$name}"
            );
        }
    }

    /**
     * This method reads all Test.xml files into objects and stores them in an array for future access.
     *
     * @return void
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     * @throws FastFailException
     * @throws TestFrameworkException
     */
    private function initTestData($validateAnnotations = true)
    {
        $testDataParser = ObjectManagerFactory::getObjectManager()->create(TestDataParser::class);
        $parsedTestArray = $testDataParser->readTestData();

        $testObjectExtractor = new TestObjectExtractor();

        if (!$parsedTestArray) {
            trigger_error("Could not parse any test in xml.", E_USER_NOTICE);
            return;
        }

        $testNameValidator = new NameValidationUtil();
        foreach ($parsedTestArray as $testName => $testData) {
            try {
                $filename = $testData[TestObjectHandler::TEST_FILENAME_ATTRIBUTE];
                $testNameValidator->validatePascalCase($testName, NameValidationUtil::TEST_NAME, $filename);
                if (!is_array($testData)) {
                    continue;
                }
                $this->tests[$testName] = $testObjectExtractor->extractTestData($testData, $validateAnnotations);
            } catch (FastFailException $exception) {
                throw $exception;
            } catch (\Exception $exception) {
                LoggingUtil::getInstance()->getLogger(self::class)->error(
                    "Unable to parse test " . $testName . "\n" . $exception->getMessage()
                );
                if (MftfApplicationConfig::getConfig()->getPhase() == MftfApplicationConfig::GENERATION_PHASE) {
                    print("ERROR: Unable to parse test " . $testName . "\n");
                }
                if (MftfApplicationConfig::getConfig()->getPhase() != MftfApplicationConfig::EXECUTION_PHASE) {
                    GenerationErrorHandler::getInstance()->addError(
                        'test',
                        $testName,
                        self::class . ': Unable to parse test ' . $exception->getMessage()
                    );
                }
            }
        }
        $testNameValidator->summarize(NameValidationUtil::TEST_NAME);
        if ($validateAnnotations) {
            $testObjectExtractor->getAnnotationExtractor()->validateStoryTitleUniqueness();
            $testObjectExtractor->getAnnotationExtractor()->validateTestCaseIdTitleUniqueness();
        }
    }

    /**
     * This method checks if the test is extended and creates a new test object accordingly
     *
     * @param TestObject $testObject
     * @return TestObject
     * @throws TestFrameworkException
     * @throws XmlException
     */
    private function extendTest($testObject)
    {
        if ($testObject->getParentName() !== null) {
            if ($testObject->getParentName() == $testObject->getName()) {
                throw new TestFrameworkException(
                    "Mftf Test can not extend from itself: " . $testObject->getName()
                );
            }
            return $this->extendUtil->extendTest($testObject);
        }
        return $testObject;
    }
}
