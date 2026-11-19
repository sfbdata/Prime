<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\EnvioAutomaticoDaPasta;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\CriarPastaUseCase;
use App\Pasta\UseCase\DuplicarPastaUseCase;
use App\Pasta\UseCase\RestaurarPastaUseCase;
use App\Sync\Service\SincronizacaoPastaDispatcher;

/**
 * O ciclo da pasta pela `atualizar_pasta` (`docs/specs/mcp-remoto-oauth.md`, 6.6): `criar`, `duplicar` e `restaurar`.
 * As três acrescentam (nada se perde) e gravam direto.
 *
 *  - `criar`: sem pasta; a linha da tabela pede o módulo `pastas`, como a tela. Como a Nova Pasta da tela
 *    (`pasta_new`): o número é gerado pelo sistema e a pasta entra ligada ao cadastro de mesmo nome de cliente;
 *  - `duplicar`: ver a pasta de origem e o módulo `pastas`, como `pasta_duplicar`; a cópia leva cliente, ação,
 *    responsável e checklist; documentos, metas e financeiro ficam;
 *  - `restaurar`: a pasta da lixeira, com o recurso `pasta` de `delete` (`pasta_restaurar`); recusa quando o cliente
 *    principal tem o envio automático ligado (a pasta voltaria a entrar no envio, abertura da 6.6).
 *
 * Criar e duplicar pedem ao Drive que crie a pasta, como a tela (só com o Drive conectado). Nenhuma ação propõe.
 */
final class AtualizarPastaCiclo implements AtualizarPastaGrupo
{
    public const RECUSA_CRIAR = 'O BlueJus recusou criar a pasta. Confira o identificador e a ação e tente de novo.';
    public const RECUSA_DUPLICAR = 'O BlueJus recusou duplicar esta pasta. Tente de novo pela tela.';
    public const NAO_ESTA_NA_LIXEIRA = 'Esta pasta não está na lixeira: não há o que restaurar.';
    public const NUMERO_REUSADO = 'O número desta pasta já é usado por outra pasta viva do escritório. Mude o número da outra antes de restaurar esta.';
    public const AVISO_DUPLICAR = 'Documentos, metas, financeiro, processos e marcadores não foram copiados.';

    public function __construct(
        private readonly AtualizarPastaAcesso $acesso,
        private readonly AtualizarPastaTexto $texto,
        private readonly EnvioAutomaticoDaPasta $envio,
        private readonly CriarPastaUseCase $criar,
        private readonly DuplicarPastaUseCase $duplicar,
        private readonly RestaurarPastaUseCase $restaurar,
        private readonly SincronizacaoPastaDispatcher $drive,
    ) {
    }

    public function acoes(): array
    {
        return ['criar', 'duplicar', 'restaurar'];
    }

    public function executar(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $pasta = match ($entrada->acao) {
            'criar' => $this->criarPasta($entrada, $chamada),
            'duplicar' => $this->duplicarPasta($entrada->pastaId(), $chamada),
            default => $this->restaurarPasta($entrada->pastaId(), $chamada),
        };
        $id = (int) $pasta->getId();
        $escritorio = $chamada->contexto->escritorio;
        $identificador = (string) $pasta->getNomeCliente();
        $dados = [
            'estado' => 'concluida',
            'acao' => $entrada->acao,
            'pasta_id' => $id,
            'numero' => $this->texto->numero($pasta, $escritorio),
            'identificador' => $identificador === '' ? null : $this->texto->protegido($escritorio, $identificador),
        ];
        $ids = [$id];
        if ($entrada->acao === 'duplicar') {
            $dados += ['origem_id' => $entrada->pastaId(), 'aviso' => self::AVISO_DUPLICAR];
            $ids[] = $entrada->pastaId();
        }

        return new RespostaDaFerramenta($dados, ids: ['pasta' => $ids]);
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        throw ErroDaFerramenta::entradaInvalida();
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        throw ErroDaFerramenta::entradaInvalida();
    }

    /** @throws ErroDaFerramenta */
    private function criarPasta(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): Pasta
    {
        $this->acesso->exigirLinha($chamada, 'criar');
        $contexto = $chamada->contexto;
        $dto = new CriarPastaDTO(
            nomeCliente: self::textoOuNulo($entrada->texto('nome_cliente')),
            nomeAcao: self::textoOuNulo($entrada->texto('nome_acao')),
            vincularAoCadastroDoMesmoNome: true,
        );
        $pasta = AtualizarPastaRecusa::gravando(fn (): Pasta => $this->criar->executar($dto, $contexto->pessoa, $contexto->escritorio), self::RECUSA_CRIAR);
        $this->drive->despachar($pasta, $contexto->pessoa, $contexto->escritorio);

        return $pasta;
    }

    /** @throws ErroDaFerramenta */
    private function duplicarPasta(int $pastaId, ChamadaDoConector $chamada): Pasta
    {
        $origem = $this->acesso->viva($chamada, 'duplicar', $pastaId, false);
        $contexto = $chamada->contexto;
        $nova = AtualizarPastaRecusa::gravando(fn (): Pasta => $this->duplicar->executar($origem, $contexto->pessoa, $contexto->escritorio), self::RECUSA_DUPLICAR);
        $this->drive->despachar($nova, $contexto->pessoa, $contexto->escritorio);

        return $nova;
    }

    /** @throws ErroDaFerramenta */
    private function restaurarPasta(int $pastaId, ChamadaDoConector $chamada): Pasta
    {
        $pasta = $this->acesso->naLixeira($chamada, $pastaId, self::NAO_ESTA_NA_LIXEIRA);
        $escritorio = $chamada->contexto->escritorio;
        if ($this->envio->ligadoParaOPrincipal($pasta, $escritorio)) {
            throw ErroDaFerramenta::envioAutomatico();
        }
        AtualizarPastaRecusa::gravando(fn () => $this->restaurar->executar($pasta, $escritorio), self::NUMERO_REUSADO);

        return $pasta;
    }

    private static function textoOuNulo(?string $texto): ?string
    {
        $texto = $texto === null ? '' : AtualizarPastaTexto::umaLinha($texto);

        return $texto === '' ? null : $texto;
    }
}
