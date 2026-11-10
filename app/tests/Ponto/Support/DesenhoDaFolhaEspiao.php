<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Support;

use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\Service\ImagemDaFolha\DesenhoDaFolha;
use App\Ponto\Service\ImagemDaFolha\ImagemDaFolha;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Espião do desenho da folha em PDF (E16): guarda cada conteúdo que a rota mandou desenhar e o endereço do QR. A página
 * do PDF é imagem, sem texto para ler de volta; o que a folha mostra se prova pelo conteúdo que chegou ao desenho.
 *
 * Por padrão devolve um JPEG pequeno (a rota embrulha qualquer JPEG), para o teste não pagar o desenho inteiro; com o
 * desenho de verdade, delega a ele. Entra no container no lugar de {@see ImagemDaFolha}, antes da primeira requisição
 * que usa o PDF ({@see instalar()}).
 */
final class DesenhoDaFolhaEspiao implements DesenhoDaFolha
{
    /** @var list<ConteudoDaFolha> */
    public array $desenhados = [];

    /** @var list<?string> */
    public array $enderecosDoQr = [];

    public function __construct(
        private readonly ?DesenhoDaFolha $deVerdade = null,
    ) {
    }

    public static function instalar(ContainerInterface $container, bool $comODesenhoDeVerdade = false): self
    {
        $deVerdade = $comODesenhoDeVerdade ? new ImagemDaFolha((string) $container->getParameter('kernel.project_dir') . '/vendor/dompdf/dompdf/lib/fonts') : null;
        $espiao = new self($deVerdade);
        $container->set(ImagemDaFolha::class, $espiao);

        return $espiao;
    }

    public function gerar(ConteudoDaFolha $conteudo, ?string $enderecoDoQr, string $enderecoDaPagina): string
    {
        $this->desenhados[] = $conteudo;
        $this->enderecosDoQr[] = $enderecoDoQr;

        return $this->deVerdade?->gerar($conteudo, $enderecoDoQr, $enderecoDaPagina) ?? self::jpegPequeno();
    }

    public function ultimo(): ConteudoDaFolha
    {
        if ($this->desenhados === []) {
            throw new \LogicException('Nenhuma folha foi desenhada.');
        }

        return $this->desenhados[array_key_last($this->desenhados)];
    }

    /**
     * Todo o texto do último conteúdo desenhado, um valor por linha: o cabeçalho, cada célula de cada dia, o resumo, os
     * intervalos, o carimbo e a validação. Serve para "aparece na folha" e "não aparece na folha".
     */
    public function textoDoUltimo(): string
    {
        $conteudo = $this->ultimo();
        $valores = [
            ...array_values(get_object_vars($conteudo->cabecalho)),
            ...array_values(get_object_vars($conteudo->resumo)),
            $conteudo->intervalos->textoDaContagem(),
            $conteudo->carimbo?->texto(),
            $conteudo->carimbo?->textoDaRessalva(),
            $conteudo->validacao?->codigo,
        ];
        foreach ($conteudo->linhas as $linha) {
            array_push($valores, $linha->dia, $linha->semana, ...$linha->batidas);
            array_push($valores, $linha->trabalhadas, $linha->extras, $linha->saldo, $linha->observacao);
        }

        return implode("\n", array_map(static fn (mixed $valor): string => (string) $valor, $valores));
    }

    private static function jpegPequeno(): string
    {
        $imagem = imagecreatetruecolor(4, 6);
        ob_start();
        imagejpeg($imagem, null, 92);

        return (string) ob_get_clean();
    }
}
