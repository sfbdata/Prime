<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\DadosDoComprovante;
use App\Ponto\Repository\ComprovanteDoPontoRepository;
use App\Ponto\Service\FuncaoDoComprovante;
use App\Ponto\Service\ImagemDoComprovante\ImagemDoComprovante;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A imagem do comprovante para o botão "Baixar" da aba Comprovantes (E15, P49). Só a própria pessoa, no escritório
 * em que está, baixa o comprovante dela: de outra pessoa, de outro escritório, que não existe ou com a função
 * desligada, devolve null (o controller responde 404 sem dizer qual).
 *
 * O QR leva o endereço absoluto da validação no próprio sistema (D20): as rotas públicas sem o prefixo `ponto_`
 * (ver {@see \App\Ponto\Controller\ValidacaoDoComprovanteController}).
 */
final class GerarImagemDoComprovanteUseCase
{
    public function __construct(
        private readonly ComprovanteDoPontoRepository $comprovantes,
        private readonly FuncaoDoComprovante $funcao,
        private readonly ImagemDoComprovante $imagem,
        private readonly UrlGeneratorInterface $rotas,
    ) {
    }

    /** @return array{nome: string, png: string}|null */
    public function executar(int $id, User $pessoa, Tenant $escritorio): ?array
    {
        $comprovante = $this->funcao->abaVisivel() ? $this->comprovantes->daPessoaPorId($id, $pessoa, $escritorio) : null;
        if ($comprovante === null) {
            return null;
        }
        try {
            $dados = DadosDoComprovante::deCanonico($comprovante->getDadosAssinados());
        } catch (\UnexpectedValueException) {
            return null;
        }

        $enderecoDoQr = $this->rotas->generate('validacao_comprovante_ponto_codigo', ['codigo' => $dados->codigo], UrlGeneratorInterface::ABSOLUTE_URL);
        $pagina = (string) preg_replace('#^[a-z]+://#i', '', $this->rotas->generate('validacao_comprovante_ponto', [], UrlGeneratorInterface::ABSOLUTE_URL));

        return [
            'nome' => 'comprovante-ponto-' . $dados->codigo . '.png',
            'png'  => $this->imagem->gerar($dados, $comprovante->getHmac(), $enderecoDoQr, $pagina),
        ];
    }
}
