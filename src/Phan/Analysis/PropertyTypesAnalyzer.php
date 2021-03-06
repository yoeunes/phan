<?php declare(strict_types=1);
namespace Phan\Analysis;

use Phan\CodeBase;
use Phan\Exception\IssueException;
use Phan\Issue;
use Phan\IssueFixSuggester;
use Phan\Language\Element\Clazz;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\Type\GenericArrayType;
use Phan\Language\Type\TemplateType;

class PropertyTypesAnalyzer
{

    /**
     * Check to see if the given properties have issues
     *
     * @return void
     */
    public static function analyzePropertyTypes(CodeBase $code_base, Clazz $clazz)
    {
        foreach ($clazz->getPropertyMap($code_base) as $property) {
            try {
                $union_type = $property->getUnionType();
            } catch (IssueException $exception) {
                Issue::maybeEmitInstance(
                    $code_base,
                    $property->getContext(),
                    $exception->getIssueInstance()
                );
                continue;
            }

            // Look at each type in the parameter's Union Type
            foreach ($union_type->withFlattenedArrayShapeOrLiteralTypeInstances()->getTypeSet() as $outer_type) {
                $type = $outer_type;
                // TODO: Expand this to ArrayShapeType
                while ($type instanceof GenericArrayType) {
                    $type = $type->genericArrayElementType();
                }

                // If its a native type or a reference to
                // self, its OK
                if ($type->isNativeType() || $type->isSelfType()) {
                    continue;
                }

                if ($type instanceof TemplateType) {
                    if ($property->isStatic()) {
                        Issue::maybeEmit(
                            $code_base,
                            $property->getContext(),
                            Issue::TemplateTypeStaticProperty,
                            $property->getFileRef()->getLineNumberStart(),
                            (string)$property->getFQSEN()
                        );
                    }
                } else {
                    // Make sure the class exists
                    $type_fqsen = FullyQualifiedClassName::fromType($type);

                    if (!$code_base->hasClassWithFQSEN($type_fqsen)
                        && !($type instanceof TemplateType)
                        && (
                            !$property->hasDefiningFQSEN()
                            || $property->getDefiningFQSEN() == $property->getFQSEN()
                        )
                    ) {
                        Issue::maybeEmitWithParameters(
                            $code_base,
                            $property->getContext(),
                            Issue::UndeclaredTypeProperty,
                            $property->getFileRef()->getLineNumberStart(),
                            [(string)$property->getFQSEN(), (string)$outer_type],
                            IssueFixSuggester::suggestSimilarClass($code_base, $property->getContext(), $type_fqsen, null, 'Did you mean', IssueFixSuggester::CLASS_SUGGEST_CLASSES_AND_TYPES)
                        );
                    }
                }
            }
        }
    }
}
