<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\Armazenamento\ChavesDoVoto;
use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\DTO\PautaNoPainel;
use App\Voto\DTO\RegrasDeParticipacaoNoPainel;
use App\Voto\DTO\ResultadoDaPauta;
use App\Voto\DTO\VotoNominalNaPauta;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\VotoNominal;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\SigiloDoVoto;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Repository\VotoNominalRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Monta a assembleia como o gestor a vê (spec voto-assembleia-condominio, seção 1): participação por pauta (quantas
 * unidades votaram e o peso delas) e, só na pauta ENCERRADA, o resultado pela ApuracaoDaPauta da base (a regra não é
 * reescrita aqui). Pauta aberta nunca leva parcial de votos.
 *
 * Pauta com grupos aptos usa o peso apto dos grupos (base do quórum). A relação nominal só vem na pauta de voto
 * aberto: depois do encerramento (aberto) ou desde a abertura (aberto ao vivo); a secreta nunca a tem.
 *
 * O contador da opção muda por SQL direto ({@see \App\Voto\Repository\OpcaoDaPautaRepository::somarVoto()}): antes
 * de apurar, as opções são relidas do banco para não apurar um contador velho que ficou na memória.
 */
final class LeituraDaAssembleia
{
    private const SEM_VOTO = ['unidades' => 0, 'peso' => '0.000000'];

    public function __construct(
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly ParticipacaoNaPautaRepository $participacoes,
        private readonly ApuracaoDaPauta $apuracao,
        private readonly EntityManagerInterface $em,
        private readonly VotoNominalRepository $nominais,
    ) {
    }

    public function montar(Assembleia $assembleia): AssembleiaNoPainel
    {
        return $this->montarComo($assembleia, false);
    }

    /**
     * Só os NÚMEROS (participação e aptos por pauta), para a leitura entre escritórios do Master: não carrega a relação
     * nominal do voto aberto, não relê pauta nem opção e não apura (`resultado` sempre null). Nunca alimenta tela do
     * escritório.
     */
    public function montarSoNumeros(Assembleia $assembleia): AssembleiaNoPainel
    {
        return $this->montarComo($assembleia, true);
    }

    private function montarComo(Assembleia $assembleia, bool $soNumeros): AssembleiaNoPainel
    {
        $totais = $this->unidades->totaisAptos($assembleia);
        $contagem = $this->participacoes->contarPorPauta($assembleia);
        $pautas = [];
        foreach ($assembleia->getPautas() as $pauta) {
            $totaisDaPauta = [] === $pauta->getGrupos() ? $totais : $this->unidades->totaisAptos($assembleia, $pauta->getGrupos());
            $pautas[] = $this->pauta($pauta, $contagem[(int) $pauta->getId()] ?? self::SEM_VOTO, $totaisDaPauta, $soNumeros);
        }

        return new AssembleiaNoPainel(
            id: (int) $assembleia->getId(),
            titulo: $assembleia->getTitulo(),
            peso: $assembleia->getPeso(),
            estado: $assembleia->getEstado(),
            criadaPorNome: $assembleia->getCriadaPorNome(),
            criadaEm: $assembleia->getCriadaEm(),
            publicadaEm: $assembleia->getPublicadaEm(),
            podeAlterarPautas: $assembleia->podeAlterarPautas(),
            podeAlterarUnidades: $assembleia->podeAlterarUnidades(),
            unidadesAptas: $totais['unidadesAptas'],
            unidadesInaptas: max(0, $assembleia->getUnidades()->count() - $totais['unidadesAptas']),
            pesoApto: $totais['pesoApto'],
            pautas: $pautas,
            arquivadaEm: $assembleia->getArquivadaEm(),
            arquivadaMotivo: $assembleia->getArquivadaMotivo(),
            arquivadaPorNome: $assembleia->getArquivadaPorNome(),
            entidade: $assembleia->getEntidadeNome(),
            cnpj: $assembleia->getCnpj(),
            cenario: $assembleia->getCenario(),
            simulacao: $assembleia->isSimulacao(),
            rotuloUnidade: $assembleia->getRotuloUnidadePersonalizado(),
            rotuloBloco: $assembleia->getRotuloBlocoPersonalizado(),
            editalNome: $assembleia->getEditalNome(),
            editalTamanho: $assembleia->getEditalTamanho(),
            editalSha256: $assembleia->getEditalSha256(),
            editalChave: $assembleia->temEdital() ? ChavesDoVoto::edital($assembleia) : null,
            editalAlteravel: $assembleia->podeAlterarEdital(),
            linkSalaVirtual: $assembleia->getLinkSalaVirtual(),
        );
    }

    /**
     * @param array{unidades: int, peso: string}           $contagem
     * @param array{pesoApto: string, unidadesAptas: int} $totais
     */
    private function pauta(PautaDaAssembleia $pauta, array $contagem, array $totais, bool $soNumeros = false): PautaNoPainel
    {
        return new PautaNoPainel(
            ...self::interrupcoes($pauta),
            id: (int) $pauta->getId(),
            ordem: $pauta->getOrdem(),
            titulo: $pauta->getTitulo(),
            descricao: $pauta->getDescricao(),
            estado: $pauta->getEstado(),
            assembleiaPublicada: EstadoDaAssembleia::Publicada === $pauta->getAssembleia()->getEstado(),
            convocacao: $pauta->getConvocacao(),
            quorumExigido: $pauta->getQuorumExigido(),
            abertaEm: $pauta->getAbertaEm(),
            abertaPorNome: $pauta->getAbertaPorNome(),
            prazoAte: $pauta->getPrazoAte(),
            encerradaEm: $pauta->getEncerradaEm(),
            encerradaPorNome: $pauta->getEncerradaPorNome(),
            motivoEncerramento: $pauta->getMotivoEncerramento(),
            opcoes: self::opcoes($pauta),
            votantes: $contagem['unidades'],
            pesoVotante: $contagem['peso'],
            unidadesAptas: $totais['unidadesAptas'],
            pesoApto: $totais['pesoApto'],
            resultado: $soNumeros ? null : $this->resultado($pauta, $totais, $contagem),
            criterio: $pauta->getCriterio(),
            base: $pauta->getBase(),
            digitos: $pauta->getDigitos(),
            vagas: $pauta->getVagas(),
            quorumPrimeira: $pauta->getQuorumPrimeira(),
            quorumSegunda: $pauta->getQuorumSegunda(),
            tipo: $pauta->getTipo(),
            participacao: $this->participacao($pauta, $soNumeros),
        );
    }

    /**
     * Suspensão, cancelamento e retirada da pauta, como argumentos nomeados de PautaNoPainel.
     *
     * @return array{suspensaEm: ?\DateTimeImmutable, suspensaMotivo: ?string, suspensaPorNome: ?string, canceladaEm: ?\DateTimeImmutable, canceladaMotivo: ?string, canceladaPorNome: ?string, retiradaEm: ?\DateTimeImmutable, retiradaMotivo: ?string, retiradaPorNome: ?string}
     */
    private static function interrupcoes(PautaDaAssembleia $pauta): array
    {
        return [
            'suspensaEm' => $pauta->getSuspensaEm(),
            'suspensaMotivo' => $pauta->getSuspensaMotivo(),
            'suspensaPorNome' => $pauta->getSuspensaPorNome(),
            'canceladaEm' => $pauta->getCanceladaEm(),
            'canceladaMotivo' => $pauta->getCanceladaMotivo(),
            'canceladaPorNome' => $pauta->getCanceladaPorNome(),
            'retiradaEm' => $pauta->getRetiradaEm(),
            'retiradaMotivo' => $pauta->getRetiradaMotivo(),
            'retiradaPorNome' => $pauta->getRetiradaPorNome(),
        ];
    }

    private function participacao(PautaDaAssembleia $pauta, bool $soNumeros = false): RegrasDeParticipacaoNoPainel
    {
        return new RegrasDeParticipacaoNoPainel(
            $pauta->getAbreEm(),
            $pauta->getEncerraEm(),
            $pauta->getGrupos(),
            $pauta->getOrdemDasCandidaturas(),
            $pauta->getSigilo(),
            $soNumeros ? null : $this->nominal($pauta),
        );
    }

    /** @return list<VotoNominalNaPauta>|null null quando a pauta é secreta ou o sigilo ainda não permite mostrar */
    private function nominal(PautaDaAssembleia $pauta): ?array
    {
        $visivel = match ($pauta->getSigilo()) {
            SigiloDoVoto::Secreto => false,
            SigiloDoVoto::Aberto => EstadoDaPauta::Encerrada === $pauta->getEstado(),
            SigiloDoVoto::AbertoAoVivo => EstadoDaPauta::Aguardando !== $pauta->getEstado(),
        };
        if (!$visivel || $pauta->cancelada()) {
            return null;
        }

        return array_map(static function (VotoNominal $v): VotoNominalNaPauta {
            $unidade = $v->getUnidade();
            $opcao = $v->getOpcao();

            return new VotoNominalNaPauta($unidade->getBloco(), $unidade->getIdentificacao(), $unidade->getResponsavel(), $opcao?->getNumero(), $opcao?->getRotulo(), $opcao?->isAbstencao() ?? false, $v->getNumeroNulo(), $v->getPeso(), $v->getRegistradaEm());
        }, $this->nominais->listarDaPauta($pauta));
    }

    /** @return list<array{numero: int, rotulo: string, descricao: ?string, propostas: ?string, composicao: ?string, unidade: ?string, foto: ?string}> */
    private static function opcoes(PautaDaAssembleia $pauta): array
    {
        return array_map(static fn (OpcaoDaPauta $o): array => [
            'numero' => $o->getNumero(),
            'rotulo' => $o->getRotulo(),
            'descricao' => $o->getDescricao(),
            'propostas' => $o->getPropostas(),
            'composicao' => $o->getComposicao(),
            'unidade' => $o->getUnidadeCandidato(),
            'foto' => $o->getFoto(),
        ], $pauta->getOpcoesDeVoto());
    }

    /**
     * @param array{pesoApto: string, unidadesAptas: int} $totais
     * @param array{unidades: int, peso: string}          $contagem quem participou da pauta (cada unidade uma vez)
     */
    private function resultado(PautaDaAssembleia $pauta, array $totais, array $contagem): ?ResultadoDaPauta
    {
        if (EstadoDaPauta::Encerrada !== $pauta->getEstado() || $pauta->cancelada()) {
            return null;
        }
        $this->em->refresh($pauta);
        foreach ($pauta->getOpcoes() as $opcao) {
            $this->em->refresh($opcao);
        }

        return $this->apuracao->apurar($pauta, $totais['pesoApto'], $totais['unidadesAptas'], $contagem);
    }
}
