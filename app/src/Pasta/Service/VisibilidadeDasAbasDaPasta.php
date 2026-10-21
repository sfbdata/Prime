<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AbasDaPastaOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Exception\AbaOcultaRecusadaException;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * A regra única de "esta pessoa vê esta aba desta pasta?" (spec pasta-ocultar-aba §1, D2).
 *
 *  - Quem configura é o Master ou um primário ativo do escritório (`RegraDosPrimarios::podeGerirPrimarios`,
 *    o mesmo critério: Master = ROLE_SUPER_ADMIN, primário = vínculo ativo com o perfil de sistema).
 *  - Quem configura NUNCA é ocultado (desenho L.4377).
 *  - Para os demais: sem regra = vê; com regra, `paraTodos` ou o id na lista = não vê.
 *
 * Usada pela tela (abas e painéis não renderizados), pela barreira das rotas
 * (`BarreiraDeAbaOcultaListener`) e pela visibilidade do financeiro (`VisibilidadeDoFinanceiroDaPasta`).
 */
final class VisibilidadeDasAbasDaPasta
{
    public function __construct(
        private readonly AbaOcultaDaPastaRepository $regras,
        private readonly RegraDosPrimarios $primarios,
        private readonly EquipeQuePodeTerAbaOculta $equipe,
    ) {
    }

    public function podeConfigurar(User $user, Tenant $tenant): bool
    {
        return $this->primarios->podeGerirPrimarios($user, $tenant);
    }

    /**
     * A guarda das escritas (spec §2): a pasta é do escritório da sessão e quem pede configura.
     *
     * @throws AbaOcultaRecusadaException
     */
    public function garantirQuePodeConfigurar(Pasta $pasta, User $quem, Tenant $tenant): void
    {
        if ($pasta->getTenant() !== $tenant) {
            throw AbaOcultaRecusadaException::pastaDeOutroEscritorio();
        }
        if (!$this->podeConfigurar($quem, $tenant)) {
            throw AbaOcultaRecusadaException::soPrimario();
        }
    }

    public function ocultaPara(Pasta $pasta, AbaDaPasta $aba, User $user): bool
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null) {
            return false;
        }

        $regra = $this->regras->daAba($pasta, $aba, $tenant);
        if ($regra === null || !$regra->alcanca((int) $user->getId())) {
            return false;
        }

        // Só consulta o vínculo quando a regra alcançaria a pessoa: o caso comum (sem regra) é uma consulta.
        return !$this->podeConfigurar($user, $tenant);
    }

    /**
     * As abas desta pasta que a pessoa não vê, com uma consulta às regras (e, se houver regra que a
     * alcance, uma ao vínculo).
     *
     * @return list<AbaDaPasta>
     */
    public function abasOcultasPara(Pasta $pasta, User $user): array
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null) {
            return [];
        }

        return $this->ocultasEntre($this->regras->daPasta($pasta, $tenant), $user, $tenant);
    }

    /** O estado das abas para a tela da pasta: o que esconder e, para quem configura, as regras. */
    public function estadoParaTela(Pasta $pasta, User $user): AbasDaPastaOutput
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null) {
            return AbasDaPastaOutput::todasVisiveis();
        }

        $regras = $this->regras->daPasta($pasta, $tenant);
        $configura = $this->podeConfigurar($user, $tenant);

        if ($configura) {
            // O título do menu conta quem a regra esconde ([EXP] L.4412): só se lê a equipe havendo regra.
            return AbasDaPastaOutput::montar($regras, [], true, $regras !== [] ? $this->equipe->idsDoEscritorio($tenant) : []);
        }

        return AbasDaPastaOutput::montar($regras, $this->ocultasEntre($regras, $user, $tenant), false);
    }

    /**
     * As abas de onde vem um acontecimento da pasta (Histórico do sistema, Timeline inteligente):
     * a regra ÚNICA que decide se ele aparece para quem tem aba oculta. Lista vazia = não é de aba
     * nenhuma (cadastro da pasta, notificação pessoal): aparece sempre.
     *
     * Famílias: a `origem` do `PastaTimelineAssembler` (documento, contrato, meta, processo, pasta),
     * `registro` (anotação da aba Dados) e o `tipo` dos eventos próprios da Timeline inteligente.
     * `contrato` é documento de honorários: é das duas abas, Documentos e Financeiro.
     * `registro_financeiro` é o cartão do sistema de pró-bono ou contrato no Registro
     * (`RegistroDoSistemaNaPasta::DO_FINANCEIRO`): das abas Dados e Financeiro.
     *
     * @return list<AbaDaPasta>
     */
    public static function abasDoEvento(?string $familia): array
    {
        return match ($familia) {
            'registro'                                                   => [AbaDaPasta::Dados],
            'registro_financeiro'                                        => [AbaDaPasta::Dados, AbaDaPasta::Financeiro],
            'documento'                                                  => [AbaDaPasta::Documentos],
            'contrato'                                                   => [AbaDaPasta::Documentos, AbaDaPasta::Financeiro],
            'meta', 'meta_concluida', 'metas_concluidas', 'prazo_meta'   => [AbaDaPasta::Tarefas],
            'processo'                                                   => [AbaDaPasta::Processo],
            'publicacao'                                                 => [AbaDaPasta::Push],
            'observacao_detalhes'                                        => [AbaDaPasta::Detalhes],
            'observacao_financeira', 'pagamento_lancado', 'pagamento_quitado', 'pagamento_vencido' => [AbaDaPasta::Financeiro],
            default                                                      => [],
        };
    }

    /** @param list<string> $abasOcultas ids das abas ocultas para quem vê */
    public static function eventoVisivel(?string $familia, array $abasOcultas): bool
    {
        foreach (self::abasDoEvento($familia) as $aba) {
            if (\in_array($aba->value, $abasOcultas, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Documento da pasta para quem tem aba oculta (revisão de permissão, decisão do orquestrador): o
     * contrato de honorários (`CATEGORIA_CONTRATO`) é dado do Financeiro e some com ele oculto. A
     * aba Documentos inteira já tem a sua barreira; aqui só o recorte do contrato.
     *
     * @param list<string> $abasOcultas
     */
    public static function documentoVisivel(?string $categoria, array $abasOcultas): bool
    {
        return $categoria !== PastaDocumento::CATEGORIA_CONTRATO
            || !\in_array(AbaDaPasta::Financeiro->value, $abasOcultas, true);
    }

    /**
     * @template T of PastaDocumento
     *
     * @param list<T>      $documentos
     * @param list<string> $abasOcultas
     *
     * @return list<T>
     */
    public static function documentosVisiveis(array $documentos, array $abasOcultas): array
    {
        return array_values(array_filter($documentos, static fn (PastaDocumento $d): bool => self::documentoVisivel($d->getCategoria(), $abasOcultas)));
    }

    /**
     * @param list<AbaOcultaDaPasta> $regras
     *
     * @return list<AbaDaPasta>
     */
    private function ocultasEntre(array $regras, User $user, Tenant $tenant): array
    {
        $userId = (int) $user->getId();
        $alcancam = array_values(array_filter($regras, static fn (AbaOcultaDaPasta $r): bool => $r->alcanca($userId)));
        if ($alcancam === [] || $this->podeConfigurar($user, $tenant)) {
            return [];
        }

        return array_map(static fn (AbaOcultaDaPasta $r): AbaDaPasta => $r->getAba(), $alcancam);
    }
}
