<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * O nome da pessoa no arquivo da folha (P87). O PDF segue o desenho 16 (L726): tira o acento (NFD) e fica só com as
 * letras, em maiúsculas: "João da Silva" vira "JOAODASILVA"; sem letra nenhuma, "USUARIO" (E16, decisão 26). O XLSX
 * (função do sistema, fora do desenho) também tira o acento, em vez de apagar a letra acentuada, e mantém a caixa e os
 * dígitos de antes; sem letra nem dígito, "Usuario".
 */
final class NomeDoArquivoDaFolha
{
    private const SEM_NOME_NO_PDF = 'USUARIO';
    private const SEM_NOME_NO_XLSX = 'Usuario';

    public static function doPdf(string $nome): string
    {
        $letras = (string) preg_replace('/[^A-Za-z]+/', '', self::semAcento($nome));

        return $letras !== '' ? strtoupper($letras) : self::SEM_NOME_NO_PDF;
    }

    public static function doXlsx(string $nome): string
    {
        $limpo = (string) preg_replace('/[^A-Za-z0-9]+/', '', self::semAcento($nome));

        return $limpo !== '' ? $limpo : self::SEM_NOME_NO_XLSX;
    }

    private static function semAcento(string $nome): string
    {
        return (string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($nome, \Normalizer::FORM_D));
    }
}
