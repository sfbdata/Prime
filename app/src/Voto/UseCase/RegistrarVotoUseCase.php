<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\DTO\RegistrarVotoInput;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Exception\CedulaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\OpcaoDaPautaRepository;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\ComprovanteDoVoto;
use App\Voto\Service\TransacaoDoVoto;
use App\Voto\Service\UnidadeDoLink;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * O morador vota numa pauta pelo link pessoal da unidade (spec docs/specs/voto-assembleia-condominio.md, seções 1 e 2).
 *
 * Quem: o morador, sem conta, pela cédula `/votar/{token}`. Pré-condições: link válido e escritório com o Voto
 * liberado; unidade apta; pauta da mesma assembleia, aberta e dentro do prazo; opções da pauta (uma só, ou até o
 * número de vagas; abstenção só sozinha); a unidade ainda não votou nela. Tudo numa transação:
 *  1. trava a linha da pauta (SELECT ... FOR UPDATE), para o voto se serializar com o encerramento pela mesa;
 *  2. prazo vencido: grava o encerramento ("Prazo definido pela mesa"), confirma a transação e recusa o voto;
 *  3. confere aberta, a lista de opções inteira (antes de gravar qualquer coisa) e voto anterior;
 *  4. INSERT da participação (unidade, pauta, hora): o UNIQUE(unidade, pauta) barra o segundo voto;
 *  5. soma o peso no contador de cada opção escolhida por SQL direto (fração da unidade, ou 1 por unidade); uma só
 *     participação, não importa quantos nomes.
 *
 * SIGILO (seção 2): nenhuma linha liga a unidade à opção. A participação não guarda a opção, e o contador só muda por
 * UPDATE direto, fora da unidade de trabalho do Doctrine (sem audit_log com a hora da mudança).
 *
 * Devolve o código de comprovante da participação ({@see ComprovanteDoVoto}): prova que a unidade votou, nunca em quê.
 *
 * A transação é a {@see TransacaoDoVoto}: uma recusa de negócio desfaz sem fechar o EntityManager.
 */
final class RegistrarVotoUseCase
{
    public const JA_VOTOU = 'Você já votou nesta pauta.';
    public const UNIDADE_INAPTA = 'Sua unidade não está apta a votar nesta assembleia.';
    public const PAUTA_NAO_ENCONTRADA = 'Pauta não encontrada nesta assembleia.';
    public const PAUTA_NAO_ABERTA = 'A votação desta pauta não está aberta.';
    public const PRAZO_ENCERRADO = 'O prazo desta votação terminou. A pauta foi encerrada.';
    public const OPCAO_INVALIDA = 'Escolha uma das opções desta pauta.';
    public const NOMES_DEMAIS = 'Você escolheu mais nomes do que as vagas desta pauta.';
    public const NOME_REPETIDO = 'Você escolheu o mesmo nome mais de uma vez.';
    public const ABSTENCAO_EXCLUSIVA = 'A abstenção não pode ser escolhida junto com um nome.';

    public function __construct(
        private readonly UnidadeDoLink $link,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly OpcaoDaPautaRepository $opcoes,
        private readonly ParticipacaoNaPautaRepository $participacoes,
        private readonly TransacaoDoVoto $transacao,
        private readonly ClockInterface $relogio,
        private readonly ComprovanteDoVoto $comprovante,
    ) {
    }

    /**
     * @throws CedulaNaoEncontradaException          link inexistente ou escritório sem o Voto liberado (404)
     * @throws OperacaoInvalidaNaAssembleiaException voto recusado; a mensagem vai para a tela
     */
    public function executar(RegistrarVotoInput $input): string
    {
        $unidade = $this->link->localizar($input->token) ?? throw new CedulaNaoEncontradaException();
        $this->exigirUnidadeApta($unidade);
        [$recusa, $codigo] = $this->votarNumaTransacao($unidade, $input, $this->relogio->now());
        if (null !== $recusa || null === $codigo) {
            throw new OperacaoInvalidaNaAssembleiaException($recusa ?? self::PAUTA_NAO_ABERTA);
        }

        return $codigo;
    }

    /** @return array{0: string|null, 1: string|null} [motivo da recusa que precisou gravar algo (o encerramento pelo prazo), código do comprovante]; recusa nula = voto registrado */
    private function votarNumaTransacao(UnidadeDaAssembleia $unidade, RegistrarVotoInput $input, \DateTimeImmutable $agora): array
    {
        $codigo = null;
        try {
            $recusa = $this->transacao->executar(function () use ($unidade, $input, $agora, &$codigo): ?string {
                [$motivo, $codigo] = $this->votarNaPautaTravada($unidade, $input, $agora);

                return $motivo;
            });

            return [$recusa, $codigo];
        } catch (UniqueConstraintViolationException $repetido) {
            // Corrida que escapou da conferência: o UNIQUE(unidade, pauta) decide.
            throw new OperacaoInvalidaNaAssembleiaException(self::JA_VOTOU, 0, $repetido);
        }
    }

    /** @return array{0: string|null, 1: string|null} */
    private function votarNaPautaTravada(UnidadeDaAssembleia $unidade, RegistrarVotoInput $input, \DateTimeImmutable $agora): array
    {
        $pauta = $this->pautas->buscarTravadaDaAssembleia($input->pautaId, $unidade->getAssembleia())
            ?? throw new OperacaoInvalidaNaAssembleiaException(self::PAUTA_NAO_ENCONTRADA);
        if ($pauta->encerrarSePrazoVenceu($agora)) {
            // A transação grava o encerramento e confirma; a recusa sai depois dela.
            return [self::PRAZO_ENCERRADO, null];
        }
        $escolhidas = $this->opcoesQueAceitamVoto($pauta, $input->opcoesIds, $agora);
        $this->exigirPrimeiroVoto($unidade, $pauta);
        $this->participacoes->salvar(new ParticipacaoNaPauta($unidade, $pauta, $agora));
        // O INSERT vai antes do contador: um segundo voto para no UNIQUE sem ter somado nada.
        $this->transacao->gravar();
        $this->opcoes->somarVotos($escolhidas, $unidade->pesoDoVoto());

        // O código usa a hora em segundos, como o banco a guarda: a conferência o refaz a partir da participação gravada.
        return [null, $this->comprovante->codigo((int) $unidade->getId(), (int) $pauta->getId(), $agora)];
    }

    /**
     * Valida a lista inteira antes de qualquer gravação: pauta aberta, lista não vazia, sem repetida, todas da pauta,
     * no máximo as vagas e a abstenção sozinha.
     *
     * @param list<int> $opcoesIds
     *
     * @return list<OpcaoDaPauta>
     */
    private function opcoesQueAceitamVoto(PautaDaAssembleia $pauta, array $opcoesIds, \DateTimeImmutable $agora): array
    {
        if (!$pauta->aceitaVotoEm($agora)) {
            throw new OperacaoInvalidaNaAssembleiaException(self::PAUTA_NAO_ABERTA);
        }
        if ([] === $opcoesIds) {
            throw new OperacaoInvalidaNaAssembleiaException(self::OPCAO_INVALIDA);
        }
        if (\count($opcoesIds) !== \count(array_unique($opcoesIds))) {
            throw new OperacaoInvalidaNaAssembleiaException(self::NOME_REPETIDO);
        }
        $escolhidas = [];
        foreach ($opcoesIds as $id) {
            $escolhidas[] = $this->opcoes->buscarDaPauta($id, $pauta)
                ?? throw new OperacaoInvalidaNaAssembleiaException(self::OPCAO_INVALIDA);
        }
        $this->exigirListaPermitida($pauta, $escolhidas);

        return $escolhidas;
    }

    /** @param list<OpcaoDaPauta> $escolhidas */
    private function exigirListaPermitida(PautaDaAssembleia $pauta, array $escolhidas): void
    {
        if (\count($escolhidas) > 1 && array_filter($escolhidas, static fn (OpcaoDaPauta $o): bool => $o->isAbstencao()) !== []) {
            throw new OperacaoInvalidaNaAssembleiaException(self::ABSTENCAO_EXCLUSIVA);
        }
        if (\count($escolhidas) > $pauta->getVagas()) {
            throw new OperacaoInvalidaNaAssembleiaException(self::NOMES_DEMAIS);
        }
    }

    private function exigirUnidadeApta(UnidadeDaAssembleia $unidade): void
    {
        if (!$unidade->isApta()) {
            throw new OperacaoInvalidaNaAssembleiaException(self::UNIDADE_INAPTA);
        }
    }

    private function exigirPrimeiroVoto(UnidadeDaAssembleia $unidade, PautaDaAssembleia $pauta): void
    {
        if ($this->participacoes->jaVotou($unidade, $pauta)) {
            throw new OperacaoInvalidaNaAssembleiaException(self::JA_VOTOU);
        }
    }
}
