<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Tarefa\DTO\EtapaDaMetaOutput;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\TipoDeDemanda;
use App\Tarefa\Service\NomesDasEtapas;
use App\Tarefa\Service\QuemCuidaDaMeta;
use App\Tarefa\Service\RotaDaPassagem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Monta o trilho "ETAPA DA PASTA" e as ações da lateral da meta (desenho 04B L43-44, L78-82, L138) e os dados do
 * modal "Concluir etapa e passar adiante" (04 L131, L195-197; 04B L88-101), com o nome da etapa renomeada pela caixa
 * em todo lugar (spec 2.2). Quem pode concluir e devolver segue o D3, pela mesma regra dos UseCases que gravam.
 */
final class MontarEtapaDaMetaUseCase
{
    public function __construct(
        private readonly NomesDasEtapas $nomes,
        private readonly QuemCuidaDaMeta $quemCuida,
        private readonly UserTenantRepository $vinculos,
        private readonly DiasUteisDoEscritorio $diasUteis,
        private readonly TranslatorInterface $tradutor,
        private readonly UserRepository $usuarios,
        private readonly UrlGeneratorInterface $urls,
        private readonly ResolvedorIdentificadorDaPasta $identificador,
    ) {
    }

    public function executar(Tarefa $meta, User $quem, Tenant $escritorio): EtapaDaMetaOutput
    {
        $aberta = $meta->getStatus() !== Tarefa::STATUS_CONCLUIDA;
        $tipo = $meta->getTipoDeDemanda();
        $proxima = $aberta ? RotaDaPassagem::proximaPadrao($tipo, $meta->getEtapa()) : null;
        [$trilho, $saltadas] = $this->trilho($meta, $escritorio, $proxima);
        $criador = $meta->getCriadoPor();
        $mexe = self::podeMexerNaEtapa($meta);

        return new EtapaDaMetaOutput(
            $trilho,
            $saltadas,
            $aberta,
            $mexe && $this->quemCuida->ehResponsavelOuCriador($meta, $quem),
            $mexe && $meta->getStatus() === Tarefa::STATUS_PENDENTE && $criador !== null && DevolverMetaUseCase::podeDevolver($meta, $quem),
            $proxima === null ? null : $this->nomes->nome($escritorio, $proxima),
            $proxima === null ? null : $this->nomes->setor($proxima),
            $criador === null ? '' : self::doisNomes($criador),
        );
    }

    /**
     * A etapa só se mexe (concluir e passar, devolver) em meta aberta. Pasta na lápide é somente leitura: sem Concluir
     * nem Devolver (o listener recusaria o POST de todo jeito). O desfazer da auditoria (`EstadoDaTelaNoDesfazer`)
     * chama esta mesma regra para `etapa` e `etapasSaltadas`, com o status que a meta terá depois de desfeita
     * (`$status`; sem ele, o atual).
     */
    public static function podeMexerNaEtapa(Tarefa $meta, ?string $status = null): bool
    {
        return ($status ?? $meta->getStatus()) !== Tarefa::STATUS_CONCLUIDA && !$meta->getPasta()->estaExcluida();
    }

    /**
     * Os dados do modal, em JSON para o `meta-passagem.js`: as 8 etapas, os tipos com as sequências, a equipe e o
     * prazo padrão de 2 dias úteis pela regra do escritório (C1).
     *
     * @return array<string, mixed>
     */
    public function dadosDoModal(Tarefa $meta, Tenant $escritorio, \DateTimeImmutable $agora): array
    {
        $etapas = [];
        foreach (EtapaDaPasta::cases() as $etapa) {
            $etapas[$etapa->value] = [
                'nome'      => $this->nomes->nome($escritorio, $etapa),
                'setor'     => $this->nomes->setor($etapa),
                'descricao' => $this->nomes->descricao($etapa),
                'icone'     => $etapa->icone(),
                'posicao'   => $etapa->posicao(),
            ];
        }
        $pasta = $meta->getPasta();

        return [
            'meta'    => [
                'id'     => $meta->getId(),
                'titulo' => $meta->getTitulo(),
                'pasta'  => $pasta->getNup() ?? '#' . $pasta->getId(),
                'cliente' => $this->identificador->para($pasta),
                'etapa'  => $meta->getEtapa()->value,
                'tipo'   => $meta->getTipoDeDemanda()->value,
            ],
            'etapas'  => $etapas,
            'tipos'   => array_map(fn (TipoDeDemanda $t): array => [
                'valor'     => $t->value,
                'rotulo'    => $this->tradutor->trans($t->rotulo()),
                'sequencia' => array_map(static fn (EtapaDaPasta $e): string => $e->value, $t->sequencia()),
            ], TipoDeDemanda::cases()),
            'membros' => $this->membros($escritorio),
            'prazo'   => $this->diasUteis->somarUteis($agora->setTime(0, 0), PassarEtapaDaMetaUseCase::PRAZO_PADRAO_EM_DIAS_UTEIS, $escritorio->getEstado())->format('Y-m-d'),
        ];
    }

    /**
     * @return array{0: list<array{nome: string, setor: string, icone: string, estado: string, dica: string}>, 1: list<string>}
     */
    private function trilho(Tarefa $meta, Tenant $escritorio, ?EtapaDaPasta $proxima): array
    {
        $atual = $meta->getEtapa();
        $saltadas = $meta->getEtapasSaltadas();
        $trilho = [];
        $nomesSaltados = [];
        foreach ($meta->getTipoDeDemanda()->sequencia() as $etapa) {
            $estado = match (true) {
                $etapa === $atual                                => 'atual',
                in_array($etapa, $saltadas, true) && $etapa->posicao() < $atual->posicao() => 'saltada',
                $etapa->posicao() < $atual->posicao()            => 'passada',
                $etapa === $proxima                              => 'proxima',
                default                                          => 'adiante',
            };
            $nome = $this->nomes->nome($escritorio, $etapa);
            $trilho[] = ['nome' => $nome, 'setor' => $this->nomes->setor($etapa), 'icone' => self::icone($etapa, $estado), 'estado' => $estado, 'dica' => $this->dica($etapa, $nome, $estado)];
            if ($estado === 'saltada') {
                $nomesSaltados[] = $nome;
            }
        }

        return [$trilho, $nomesSaltados];
    }

    /** O ícone de cada estado, como o desenho (04B L138). */
    private static function icone(EtapaDaPasta $etapa, string $estado): string
    {
        return match ($estado) {
            'atual'   => 'bi-record-circle',
            'saltada' => 'bi-skip-forward',
            'passada' => 'bi-check-lg',
            'proxima' => 'bi-arrow-right',
            default   => $etapa->icone(),
        };
    }

    private function dica(EtapaDaPasta $etapa, string $nome, string $estado): string
    {
        $dica = $nome . ' · ' . $this->nomes->setor($etapa);

        return match ($estado) {
            'saltada' => $dica . ' · ' . $this->tradutor->trans('metas.passagem.trilho.dica_saltada'),
            'passada' => $dica . ' · ' . $this->tradutor->trans('metas.passagem.trilho.dica_concluida'),
            default   => $dica,
        };
    }

    /**
     * A equipe do seletor do responsável: nome, iniciais, "cargo · lotação" e a foto do perfil pelo mesmo caminho do
     * avatar do sistema (`app_profile_foto_serve`); sem foto, as iniciais (04 L195).
     *
     * @return list<array{id: ?int, nome: string, iniciais: string, funcao: string, foto: ?string}>
     */
    private function membros(Tenant $escritorio): array
    {
        $fotos = $this->usuarios->findFotoPorColaboradores($escritorio);
        $membros = [];
        foreach ($this->vinculos->findAtivosComCargoELotacaoPorTenant($escritorio) as $vinculo) {
            $foto = $fotos[(int) $vinculo->getUser()->getId()] ?? null;
            $membros[] = self::membro($vinculo) + [
                'foto' => $foto === null || $foto === '' ? null : $this->urls->generate('app_profile_foto_serve', ['nome' => $foto]),
            ];
        }

        return $membros;
    }

    /** @return array{id: ?int, nome: string, iniciais: string, funcao: string} */
    private static function membro(UserTenant $vinculo): array
    {
        $pessoa = $vinculo->getUser();
        $nome = mb_convert_case(trim((string) ($pessoa->getFullName() ?? $pessoa->getEmail())), MB_CASE_TITLE);
        $partes = array_slice(array_values(array_filter(explode(' ', $nome))), 0, 2);
        $funcao = array_filter([$vinculo->getCargo()?->getNome(), $vinculo->getLotacao()?->getNome()]);

        return [
            'id'       => $pessoa->getId(),
            'nome'     => $nome,
            'iniciais' => mb_strtoupper(implode('', array_map(static fn (string $p): string => mb_substr($p, 0, 1), $partes))),
            'funcao'   => implode(' · ', $funcao),
        ];
    }

    /** "Devolver para Primeiro Segundo" (04B L81): os dois primeiros nomes de quem criou a meta. */
    private static function doisNomes(User $pessoa): string
    {
        $nome = mb_convert_case(trim((string) ($pessoa->getFullName() ?? $pessoa->getEmail())), MB_CASE_TITLE);

        return implode(' ', array_slice(explode(' ', $nome), 0, 2));
    }
}
