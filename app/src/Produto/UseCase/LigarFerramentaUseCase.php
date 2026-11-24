<?php

declare(strict_types=1);

namespace App\Produto\UseCase;

use App\Backup\Service\DonoDoEscritorio;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Exception\FerramentaRecusadaException;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Service\TenantBootstrapService;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use Psr\Clock\ClockInterface;

/**
 * O dono liga uma ferramenta no Meu Perfil (spec docs/specs/multiproduto-contratacao-e-voto.md, §9). Na ordem: só o
 * dono (403 antes de tudo); `completo` não se liga (404); travada pelo Master (D16, 422). Depois, sob a trava do
 * escritório: garante a contratação (sem linha nenhuma, nada é gravado: o escritório já tem tudo) e
 *
 * - Voto: libera a `LiberacaoDoVoto` (cria se faltar). A chave global `voto` do Master continua valendo;
 * - I.A. (regra 3): libera pelo dono e, só com o orçamento nulo, grava `Incluso` com o teto padrão (D14). Não grava
 *   aceite: ele continua com o admin;
 * - Expediente (regra 10): semeia os marcadores padrão se o escritório não tem nenhum.
 */
final class LigarFerramentaUseCase
{
    /** O teto padrão da I.A. quando a `configuracao_global` não tem a chave (US$ 5,00 em centavos). */
    public const TETO_PADRAO_DE_RESERVA = 500;

    public function __construct(
        private readonly DonoDoEscritorio $dono,
        private readonly ContratacoesDoEscritorio $contratacoes,
        private readonly LiberacaoDoVotoRepository $liberacoesDoVoto,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoesDaIa,
        private readonly ConfiguracaoGlobalRepository $configuracaoGlobal,
        private readonly TenantBootstrapService $bootstrap,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws FerramentaRecusadaException */
    public function executar(User $dono, Tenant $tenant, Produto $produto): void
    {
        if (!$this->dono->ehDono($dono, $tenant)) {
            throw FerramentaRecusadaException::soODono();
        }
        if (Produto::Completo === $produto) {
            throw FerramentaRecusadaException::naoLigavel();
        }

        $this->contratacoes->sobTrava($tenant, fn () => $this->ligarSobTrava($dono, $tenant, $produto));
    }

    private function ligarSobTrava(User $dono, Tenant $tenant, Produto $produto): void
    {
        if ($this->contratacoes->travadoPeloMaster($tenant, $produto)) {
            throw FerramentaRecusadaException::travadaPeloMaster();
        }

        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $this->contratacoes->incluir($tenant, $produto, OrigemDaContratacao::Dono, $dono, $agora);
        if (Produto::Voto === $produto) {
            $this->liberarOVoto($tenant);
        }
        if (Produto::Ia === $produto) {
            $this->liberarAIa($tenant);
        }
        if (Produto::Expediente === $produto) {
            $this->bootstrap->semearMarcadoresSeFaltarem($tenant, $dono);
        }
    }

    private function liberarOVoto(Tenant $tenant): void
    {
        $liberacao = $this->liberacoesDoVoto->findDoTenant($tenant) ?? new LiberacaoDoVoto($tenant);
        $liberacao->liberar();
        $this->liberacoesDoVoto->salvar($liberacao, flush: true);
    }

    private function liberarAIa(Tenant $tenant): void
    {
        $configuracao = $this->configuracoesDaIa->findDoTenant($tenant) ?? new ConfiguracaoDeInteligencia($tenant);
        $configuracao->liberarPeloDono();
        // D14: o teto que o Master já ajustou (ou que veio de um ligar anterior) fica como está.
        if (null === $configuracao->getOrcamentoMensalCentavos()) {
            $configuracao->definirCobranca(ModoDeCobrancaDaIa::Incluso, $this->configuracaoGlobal->tetoPadraoDaIaCentavos() ?? self::TETO_PADRAO_DE_RESERVA);
        }
        $this->configuracoesDaIa->salvar($configuracao, flush: true);
    }
}
