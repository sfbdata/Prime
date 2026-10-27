<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Master\DTO\ConfiguracaoGlobalOutput;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\Service\CatalogoDeConfiguracoes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lê as configurações globais para a seção do /master.
 *
 * Quem dispara: o Master, ao abrir "Configurações globais" (o controller exige ROLE_SUPER_ADMIN).
 * Fluxo principal: padrões do catálogo, por cima o que está gravado, e os dois campos de outra fonte lidos na
 * fonte: o espaço do Plano Free (cota do plano) e o aviso de espaço (faixa de Alerta). Sem linha gravada, valem os
 * padrões. Não grava nada.
 */
final class ConsultarConfiguracaoGlobalUseCase
{
    public function __construct(
        private readonly ConfiguracaoGlobalRepository $configuracoes,
        private readonly PlanoRepository $planos,
        private readonly ConfiguracaoDoEspacoRepository $espaco,
        #[Autowire('%app.sessao_padrao_minutos%')]
        private readonly ?int $sessaoPadraoMinutos = null,
    ) {
    }

    public function executar(): ConfiguracaoGlobalOutput
    {
        $gravada = $this->configuracoes->buscarUnica();
        $sobrepor = $this->sessaoPadraoMinutos === null ? [] : CatalogoDeConfiguracoes::sobreposicoes($this->sessaoPadraoMinutos);
        $valores = array_merge(CatalogoDeConfiguracoes::padroes($sobrepor), $gravada?->getValores() ?? []);
        $valores[CatalogoDeConfiguracoes::CAMPO_FREE_GB] = (string) $this->planos->free()->getCotaGb();
        $valores[CatalogoDeConfiguracoes::CAMPO_AVISO] = (string) $this->espaco->atual()->getFaixaAlerta();

        return new ConfiguracaoGlobalOutput($valores, $gravada?->getAlteradoEm(), $sobrepor);
    }
}
