<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Contribua\DTO\AlterarPlanoInput;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\UseCase\AlterarPlanoUseCase;
use App\Contribua\UseCase\ConfigurarFaixasDoEspacoUseCase;
use App\Master\DTO\ConfiguracaoGlobalOutput;
use App\Master\DTO\SalvarConfiguracaoGlobalInput;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Exception\ConfiguracaoRecusada;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\Service\CatalogoDeConfiguracoes;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Salva as configurações globais do sistema (desenho 05 Perfis 1.2 VS 25.10.26, botão "Salvar").
 *
 * Quem dispara: o Master, na seção "Configurações globais" de /master (o controller exige ROLE_SUPER_ADMIN).
 * Objetivo: gravar de uma vez os 14 campos do desenho, cada um na sua fonte.
 * Pré-condições: todo valor passa pelo CatalogoDeConfiguracoes (intervalo, opção, e-mail, data).
 * Fluxo principal: valida o pedido inteiro; se o aviso de espaço mudou, ajusta a faixa de Alerta (a Contribua exige
 * as faixas em ordem); se o espaço do Free mudou, altera a cota do plano Free; grava os outros 12 campos na linha
 * única. Tudo no escopo de plataforma (auditoria sem escritório).
 * Fluxos alternativos: valor inválido ou faixa fora de ordem recusa o pedido todo (422), sem gravar nada, porque a
 * faixa é a única regra de outra fonte e roda antes de qualquer gravação; pedido igual ao que já vale não grava.
 * Pós-condições: o aviso de manutenção, quando houver, aparece para todos os usuários logados.
 * Auditoria: ConfiguracaoGlobal, Plano e ConfiguracaoDoEspaco são Auditavel.
 */
final class SalvarConfiguracaoGlobalUseCase
{
    public function __construct(
        private readonly ConfiguracaoGlobalRepository $configuracoes,
        private readonly ConsultarConfiguracaoGlobalUseCase $consultar,
        private readonly ConfigurarFaixasDoEspacoUseCase $faixas,
        private readonly ConfiguracaoDoEspacoRepository $espaco,
        private readonly PlanoRepository $planos,
        private readonly AlterarPlanoUseCase $alterarPlano,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        #[Autowire('%app.sessao_padrao_minutos%')]
        private readonly ?int $sessaoPadraoMinutos = null,
    ) {
    }

    public function executar(SalvarConfiguracaoGlobalInput $input): ConfiguracaoGlobalOutput
    {
        $novos = CatalogoDeConfiguracoes::normalizar(
            $input->valores,
            $this->sessaoPadraoMinutos === null ? [] : CatalogoDeConfiguracoes::sobreposicoes($this->sessaoPadraoMinutos),
        );
        $atuais = $this->consultar->executar()->valores;

        $this->escopoDePlataforma->executar(function () use ($novos, $atuais, $input): void {
            $this->aplicarAviso($novos, $atuais);
            $this->aplicarFree($novos, $atuais);
            $this->aplicarGravados($novos, $atuais, $input);
        });

        return $this->consultar->executar();
    }

    /**
     * @param array<string, string> $novos
     * @param array<string, string> $atuais
     */
    private function aplicarAviso(array $novos, array $atuais): void
    {
        $chave = CatalogoDeConfiguracoes::CAMPO_AVISO;
        if ($novos[$chave] === $atuais[$chave]) {
            return;
        }

        $faixas = $this->espaco->atual();
        try {
            $this->faixas->executar($faixas->getFaixaAtencao(), (int) $novos[$chave], $faixas->getFaixaCritico(), $faixas->getFaixaBloqueio(), $faixas->isBloqueioAtivo());
        } catch (\DomainException) {
            throw new ConfiguracaoRecusada(sprintf(
                'O aviso de espaço precisa ficar entre a faixa de Atenção (%d%%) e a de Crítico (%d%%).',
                $faixas->getFaixaAtencao(),
                $faixas->getFaixaCritico(),
            ));
        }
    }

    /**
     * @param array<string, string> $novos
     * @param array<string, string> $atuais
     */
    private function aplicarFree(array $novos, array $atuais): void
    {
        $chave = CatalogoDeConfiguracoes::CAMPO_FREE_GB;
        if ($novos[$chave] === $atuais[$chave]) {
            return;
        }

        $free = $this->planos->free();
        $this->alterarPlano->executar(new AlterarPlanoInput(
            $free->getCodigo(),
            $free->getNome(),
            (int) $novos[$chave],
            $free->getPrecoCentavos(),
            $free->getLimiteUsuarios(),
            $free->getDescricao(),
            $free->isAtivo(),
        ));
    }

    /**
     * @param array<string, string> $novos
     * @param array<string, string> $atuais
     */
    private function aplicarGravados(array $novos, array $atuais, SalvarConfiguracaoGlobalInput $input): void
    {
        $gravados = [];
        foreach (CatalogoDeConfiguracoes::chavesGravadas() as $chave) {
            $gravados[$chave] = $novos[$chave];
        }

        $linha = $this->configuracoes->buscarUnica();
        $mudou = array_diff_assoc($gravados, array_intersect_key($atuais, $gravados)) !== [];
        if (!$mudou) {
            return;
        }

        $linha ??= new ConfiguracaoGlobal();
        $linha->definirValores($gravados, $input->ator, $this->relogio->now());
        $this->configuracoes->salvar($linha, flush: true);
    }
}
