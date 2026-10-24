<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\DocumentoDoCurriculo;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * "Word" do cartão "Meu currículo" (desenho 06 de 24/10, `mcWord`, l.1227): o MESMO documento da
 * pré-visualização, com o BOM na frente, para o Word abrir como `.doc` ("Currículo - {nome}.doc"). Fora
 * do sistema não há sessão: a foto vai embutida e o logotipo com a URL absoluta ({@see DocumentoDoCurriculo::word()}).
 *
 * Só o próprio currículo de quem está logado: não recebe código, então não há como pedir o de outra
 * pessoa; o interno não tem "Meu currículo" e quem ainda não cadastrou recebe "não encontrado".
 */
final class GerarWordDoMeuCurriculoUseCase
{
    public const BOM = "\u{FEFF}";

    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly DocumentoDoCurriculo $documento,
    ) {
    }

    /** @return array{conteudo: string, nome: string, nomeAscii: string} */
    public function executar(Contexto $ctx): array
    {
        $cv = $this->localizador->meu($ctx) ?? throw new DemandaNaoEncontrada('Currículo não encontrado.');
        $nome = trim($cv->texto('nome'));
        $nome = $nome !== '' ? $nome : 'BlueJus';
        $ascii = trim((new AsciiSlugger('pt_BR'))->slug($nome, ' ')->toString());

        return [
            'conteudo' => self::BOM . $this->documento->word($this->documento->dadosDe($cv), $cv),
            'nome' => 'Currículo - ' . str_replace(['/', '\\'], ' ', $nome) . '.doc',
            'nomeAscii' => 'Curriculo - ' . ($ascii !== '' ? $ascii : 'BlueJus') . '.doc',
        ];
    }
}
