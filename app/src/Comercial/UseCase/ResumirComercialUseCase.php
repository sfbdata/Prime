<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\DTO\ResumoDoComercialOutput;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\ConfirmacaoDeEnderecoRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;

/**
 * Os KPIs do fim da seção Comercial (spec comercial-e-emails §10, item 10; desenho 05 VS 21.10.26, L325 e L690), só
 * leitura, para o Master:
 *   - Campanhas: enviadas ou agendadas (todas menos as canceladas), com as de teste;
 *   - E-mails na fila: os envios de campanha ainda por sair (enfileirados ou saindo);
 *   - Base de contatos: os confirmados da lista `comercial_campanhas`, sem os descadastrados (o repositório da
 *     confirmação deixa essa retirada para quem chama);
 *   - Descadastrados: os ativos da lista, isto é, de `comercial_campanhas` ou de `todos` (a mesma conta da lista).
 */
final class ResumirComercialUseCase
{
    public const ASSUNTOS_DA_LISTA = [ComposicaoDaCampanha::LISTA, DescadastroDeEmailInterface::ASSUNTO_TODOS];

    public function __construct(
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly EnvioDeEmailRepository $envios,
        private readonly ConfirmacaoDeEnderecoRepository $confirmacoes,
        private readonly DescadastroDeEmailInterface $descadastro,
    ) {
    }

    public function executar(): ResumoDoComercialOutput
    {
        return new ResumoDoComercialOutput(
            $this->campanhas->contarNaoCanceladas(),
            $this->envios->contarDeCampanhaNaFila(),
            $this->base(),
            $this->descadastro->listar(null, null, 1, 1, self::ASSUNTOS_DA_LISTA)->total,
        );
    }

    private function base(): int
    {
        $confirmados = $this->confirmacoes->confirmadosDaLista(ComposicaoDaCampanha::LISTA);
        $fora = $this->descadastro->filtrarDescadastrados(null, $confirmados, ComposicaoDaCampanha::LISTA);

        return count($confirmados) - count(array_unique($fora));
    }
}
