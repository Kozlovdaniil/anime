<?php

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\TokenType;

/**
 * JSONB_CONTAINS(field, value) — есть ли в json-массиве элемент, равный value.
 *
 * Сравнение без учёта регистра и пробелов: в БД жанры хранятся по-разному
 * («3D» и «3d», «Супер сила» и «суперсила»), а с amd приходят с заглавной.
 */
class JsonbContains extends FunctionNode
{
    public $firstJsonField = null;
    public $secondJsonValue = null;

    public function getSql(\Doctrine\ORM\Query\SqlWalker $sqlWalker): string
    {
        $field = $this->firstJsonField->dispatch($sqlWalker);
        $value = $this->secondJsonValue->dispatch($sqlWalker);

        return sprintf(
            "EXISTS (SELECT 1 FROM jsonb_array_elements_text((%s)::jsonb) AS _amd_genre"
            ." WHERE lower(replace(_amd_genre, ' ', '')) = lower(replace((%s)::text, ' ', '')))",
            $field,
            $value
        );
    }

    public function parse(\Doctrine\ORM\Query\Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->firstJsonField = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->secondJsonValue = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
