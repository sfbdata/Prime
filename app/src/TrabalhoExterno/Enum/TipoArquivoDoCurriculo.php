<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/**
 * Os quatro arquivos do currículo e o que cada um aceita (spec VS 18.10.26 §4.5, decisão 5). O tipo
 * é conferido pelo CONTEÚDO (`finfo`), nunca pelo nome ou pelo tipo que o navegador declara.
 */
enum TipoArquivoDoCurriculo: string
{
    case Foto = 'foto';
    case Oab = 'oab';
    case Matricula = 'matricula';
    case Curriculo = 'curriculo';

    private const PDF = 'application/pdf';
    private const JPG = 'image/jpeg';
    private const PNG = 'image/png';

    /** O limite do formulário do desenho é `1.5e6` (l.1172); a foto vai até 5 MB e é reduzida a 256×256. */
    public function limiteEmBytes(): int
    {
        return $this === self::Foto ? 5_000_000 : 1_500_000;
    }

    public function mensagemDeTamanho(): string
    {
        return $this === self::Foto
            ? 'A foto passa de 5 MB. Envie uma imagem menor.'
            : 'O arquivo passa de 1,5 MB. Envie um arquivo menor.';
    }

    public function mensagemDeTipo(): string
    {
        return match ($this) {
            self::Foto => 'Envie a foto em JPG ou PNG.',
            self::Curriculo => 'Envie o currículo em PDF, DOC ou DOCX.',
            default => 'Envie o arquivo em PDF, JPG ou PNG.',
        };
    }

    /** @return array<string, string> tipo pelo conteúdo → extensão gravada */
    public function tiposAceitos(): array
    {
        return match ($this) {
            self::Foto => [self::JPG => 'jpg', self::PNG => 'png'],
            self::Curriculo => [
                self::PDF => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.ms-office' => 'doc',
                'application/cdfv2' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            ],
            default => [self::PDF => 'pdf', self::JPG => 'jpg', self::PNG => 'png'],
        };
    }

    /** Nome do download quando o original não serve (a foto é regravada em JPG). */
    public function nomePadrao(): string
    {
        return match ($this) {
            self::Foto => 'foto.jpg',
            self::Oab => 'carteira-oab',
            self::Matricula => 'comprovante-curso',
            self::Curriculo => 'curriculo',
        };
    }
}
