<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Service\GuardaDoMasterDaIa;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * O Master define o teto mensal padrão da I.A. que o dono liga sozinho (spec docs/specs/multiproduto-contratacao-e-voto.md,
 * §10 e D14). Vale só para quem o dono ligar depois da troca: os tetos já gravados por escritório não mudam (o
 * {@see \App\Produto\UseCase\LigarFerramentaUseCase} só grava o padrão quando o teto do escritório está nulo).
 *
 * Grava em `configuracao_global`, no escopo de auditoria de plataforma: a troca entra na Trilha de Auditoria com quem e
 * quando. Faixa: de 0 a {@see ConfiguracaoDeInteligencia::ORCAMENTO_MAXIMO_CENTAVOS}; fora dela, `\InvalidArgumentException`
 * (422 no controller).
 */
final class AlterarTetoPadraoDaIaUseCase
{
    public function __construct(
        private readonly ConfiguracaoGlobalRepository $configuracoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws \InvalidArgumentException teto fora da faixa */
    public function executar(int $centavos, User $master): void
    {
        GuardaDoMasterDaIa::exigir($master);
        if ($centavos < 0 || $centavos > ConfiguracaoDeInteligencia::ORCAMENTO_MAXIMO_CENTAVOS) {
            throw new \InvalidArgumentException('O teto padrão precisa ficar entre US$ 0,00 e US$ 100.000,00.');
        }
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        $this->escopoDePlataforma->executar(function () use ($centavos, $master, $agora): void {
            $linha = $this->configuracoes->buscarUnica() ?? new ConfiguracaoGlobal();
            $linha->definirTetoPadraoDaIa($centavos, $master, $agora);
            $this->configuracoes->salvar($linha, flush: true);
        });
    }
}
