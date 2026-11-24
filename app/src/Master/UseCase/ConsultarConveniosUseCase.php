<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Entity\Convenio;
use App\Master\Repository\ConvenioRepository;
use App\Master\Repository\ConvenioVinculoRepository;
use App\Master\Repository\PrimariosDosEscritoriosRepository;
use App\Master\Service\FimDoConvenio;
use Psr\Clock\ClockInterface;

/**
 * A lista de convênios do painel do Master (spec docs/specs/convenios.md, §7.2): cada convênio com as contas
 * vinculadas e quantos e-mails sairiam ao desligar, e o mesmo somado por instituição (§7.5). Só leitura.
 */
final class ConsultarConveniosUseCase
{
    public function __construct(
        private readonly ConvenioRepository $convenios,
        private readonly ConvenioVinculoRepository $vinculos,
        private readonly PrimariosDosEscritoriosRepository $primarios,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @return array{hoje: string, prazoMinimo: string, convenios: list<array<string, mixed>>, instituicoes: array<string, array{nome: string, convenios: int, contas: int, emails: int}>} */
    public function executar(): array
    {
        $hoje = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $tenantsPorConvenio = $this->vinculos->tenantsAbertosPorConvenio();
        $linhas = [];
        $porInstituicao = [];
        foreach ($this->convenios->todos() as $convenio) {
            $tenants = $tenantsPorConvenio[(int) $convenio->getId()] ?? [];
            $linhas[] = $this->linha($convenio, \count($tenants), \count($this->primarios->dos($tenants)));
            $grupo = $porInstituicao[$convenio->getInstituicaoChave()] ??= ['nome' => $convenio->getInstituicao(), 'convenios' => 0, 'tenants' => []];
            $porInstituicao[$convenio->getInstituicaoChave()] = ['nome' => $grupo['nome'], 'convenios' => $grupo['convenios'] + 1, 'tenants' => array_merge($grupo['tenants'], $tenants)];
        }

        return [
            'hoje' => $hoje->format('Y-m-d'),
            'prazoMinimo' => FimDoConvenio::prazoMinimo($hoje)->format('Y-m-d'),
            'convenios' => $linhas,
            'instituicoes' => array_map(fn (array $g): array => $this->instituicao($g), $porInstituicao),
        ];
    }

    /** @return array<string, mixed> */
    private function linha(Convenio $c, int $contas, int $emails): array
    {
        return [
            'id' => $c->getId(), 'chave' => $c->getChave(), 'nome' => $c->getNome(),
            'instituicao' => $c->getInstituicao(), 'instituicaoChave' => $c->getInstituicaoChave(),
            'abrangencia' => $c->getAbrangencia()->value, 'uf' => $c->getUf(), 'prova' => $c->getProva()->value,
            'beneficio' => $c->getBeneficio()->value, 'beneficioValor' => $c->getBeneficioValor(), 'beneficioReal' => $c->getBeneficio()->ehReal(),
            'textoSelo' => $c->getTextoSelo(), 'codigoConvite' => $c->getCodigoConvite(), 'limiteDeUsos' => $c->getLimiteDeUsos(),
            'validoDe' => $c->getValidoDe()->format('Y-m-d'), 'validoAte' => $c->getValidoAte()?->format('Y-m-d'),
            'ativo' => $c->isAtivo(), 'encerraEm' => $c->getEncerraEm()?->format('Y-m-d'), 'motivo' => $c->getMotivo(),
            'contas' => $contas, 'emails' => $emails, 'oab' => $c->ehOab(),
        ];
    }

    /**
     * @param array{nome: string, convenios: int, tenants: list<int>} $grupo
     *
     * @return array{nome: string, convenios: int, contas: int, emails: int}
     */
    private function instituicao(array $grupo): array
    {
        $tenants = array_values(array_unique($grupo['tenants']));

        return ['nome' => $grupo['nome'], 'convenios' => $grupo['convenios'], 'contas' => \count($tenants), 'emails' => \count($this->primarios->dos($tenants))];
    }
}
