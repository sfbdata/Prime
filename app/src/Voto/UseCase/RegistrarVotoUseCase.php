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
use App\Voto\Service\TransacaoDoVoto;
use App\Voto\Service\UnidadeDoLink;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * O morador vota numa pauta pelo link pessoal da unidade (spec docs/specs/voto-assembleia-condominio.md, seções 1 e 2).
 *
 * Quem: o morador, sem conta, pela cédula `/votar/{token}`. Pré-condições: link válido e escritório com o Voto
 * liberado; unidade apta; pauta da mesma assembleia, aberta e dentro do prazo; opção da pauta; a unidade ainda não
 * votou nela. Tudo numa transação:
 *  1. trava a linha da pauta (SELECT ... FOR UPDATE), para o voto se serializar com o encerramento pela mesa;
 *  2. prazo vencido: grava o encerramento ("Prazo definido pela mesa"), confirma a transação e recusa o voto;
 *  3. confere aberta, opção da pauta e voto anterior;
 *  4. INSERT da participação (unidade, pauta, hora): o UNIQUE(unidade, pauta) barra o segundo voto;
 *  5. soma o peso no contador da opção por SQL direto (fração da unidade, ou 1 por unidade).
 *
 * SIGILO (seção 2): nenhuma linha liga a unidade à opção. A participação não guarda a opção, e o contador só muda por
 * UPDATE direto, fora da unidade de trabalho do Doctrine (sem audit_log com a hora da mudança).
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

    public function __construct(
        private readonly UnidadeDoLink $link,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly OpcaoDaPautaRepository $opcoes,
        private readonly ParticipacaoNaPautaRepository $participacoes,
        private readonly TransacaoDoVoto $transacao,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @throws CedulaNaoEncontradaException          link inexistente ou escritório sem o Voto liberado (404)
     * @throws OperacaoInvalidaNaAssembleiaException voto recusado; a mensagem vai para a tela
     */
    public function executar(RegistrarVotoInput $input): void
    {
        $unidade = $this->link->localizar($input->token) ?? throw new CedulaNaoEncontradaException();
        $this->exigirUnidadeApta($unidade);
        $recusa = $this->votarNumaTransacao($unidade, $input, $this->relogio->now());
        if (null !== $recusa) {
            throw new OperacaoInvalidaNaAssembleiaException($recusa);
        }
    }

    /** @return string|null o motivo da recusa que precisou gravar algo (o encerramento pelo prazo); null = voto registrado */
    private function votarNumaTransacao(UnidadeDaAssembleia $unidade, RegistrarVotoInput $input, \DateTimeImmutable $agora): ?string
    {
        try {
            return $this->transacao->executar(fn (): ?string => $this->votarNaPautaTravada($unidade, $input, $agora));
        } catch (UniqueConstraintViolationException $repetido) {
            // Corrida que escapou da conferência: o UNIQUE(unidade, pauta) decide.
            throw new OperacaoInvalidaNaAssembleiaException(self::JA_VOTOU, 0, $repetido);
        }
    }

    private function votarNaPautaTravada(UnidadeDaAssembleia $unidade, RegistrarVotoInput $input, \DateTimeImmutable $agora): ?string
    {
        $pauta = $this->pautas->buscarTravadaDaAssembleia($input->pautaId, $unidade->getAssembleia())
            ?? throw new OperacaoInvalidaNaAssembleiaException(self::PAUTA_NAO_ENCONTRADA);
        if ($pauta->encerrarSePrazoVenceu($agora)) {
            // A transação grava o encerramento e confirma; a recusa sai depois dela.
            return self::PRAZO_ENCERRADO;
        }
        $opcao = $this->opcaoQueAceitaVoto($pauta, $input->opcaoId, $agora);
        $this->exigirPrimeiroVoto($unidade, $pauta);
        $this->participacoes->salvar(new ParticipacaoNaPauta($unidade, $pauta, $agora));
        // O INSERT vai antes do contador: um segundo voto para no UNIQUE sem ter somado nada.
        $this->transacao->gravar();
        $this->opcoes->somarVoto($opcao, $unidade->pesoDoVoto());

        return null;
    }

    private function opcaoQueAceitaVoto(PautaDaAssembleia $pauta, int $opcaoId, \DateTimeImmutable $agora): OpcaoDaPauta
    {
        if (!$pauta->aceitaVotoEm($agora)) {
            throw new OperacaoInvalidaNaAssembleiaException(self::PAUTA_NAO_ABERTA);
        }

        return $this->opcoes->buscarDaPauta($opcaoId, $pauta)
            ?? throw new OperacaoInvalidaNaAssembleiaException(self::OPCAO_INVALIDA);
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
