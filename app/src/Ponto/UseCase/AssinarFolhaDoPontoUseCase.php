<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AceiteDaFolhaOutput;
use App\Ponto\DTO\AssinarFolhaInput;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\DadosDoAceiteDaFolha;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Exception\AssinaturaDaFolhaRecusadaException;
use App\Ponto\Repository\AceiteDaFolhaDoPontoRepository;
use App\Ponto\Service\ConteudoDaFolhaDoMes;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\EmissaoDaFolha;
use App\Ponto\Service\EstadoDoAceiteDaFolha;
use App\Ponto\Service\RelogioDaFolha;
use App\Ponto\Service\SeloDaFolha;
use App\Ponto\Service\TrilhaDoPonto;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grava o aceite mensal da folha (spec ponto-desenho-0811, E16, P22 e P33; decisões 1, 3, 14, 18 e 19; desenho 16
 * L290 e L809).
 *
 * Quem: a própria pessoa, na /ponto, no escritório da sessão (nunca a folha de outro, nunca outro escritório).
 * Para quê: confirmar que os registros do mês fechado representam a jornada dela, ou assinar com ressalva.
 * Pré-condições: competência válida (mês de 1 a 12, ano com quatro dígitos); a função `ponto-aceite` vale e o selo da
 * folha está pronto; o mês está fechado pelo relógio do servidor; a competência está na lista da folha dela (início da
 * contagem neste escritório); não há aceite que valha (sem aceite, ou a folha mudou depois do último); na ressalva,
 * texto com até 1.000 caracteres.
 * Fluxo principal: monta a folha de agora; confere as pré-condições ({@see EstadoDoAceiteDaFolha}); numa transação,
 * emite a folha vista (código BJF, decisão 18), grava o aceite com o número seguinte ao do último aceite lido na
 * conferência e selado com HMAC (decisão 14), dá o flush único e só então grava na trilha "Folha emitida" e "Folha
 * assinada" ou "Folha assinada com ressalva" (a trilha guarda os ids; a ressalva não entra nela).
 * Fluxos alternativos: cada pré-condição que falha é uma {@see AssinaturaDaFolhaRecusadaException} e nada é gravado (a
 * competência fora do calendário recebe a mesma recusa do mês fora da folha); dois envios ao mesmo tempo leem o mesmo
 * último aceite, recebem o mesmo número e o índice único recusa o segundo ("Esta folha já foi assinada."); a trilha que
 * falha desfaz a folha vista e o aceite.
 * Pós-condições: uma folha emitida nova (a vista), um aceite novo que passa a valer e os dois eventos na trilha do
 * escritório. Não há desfazer (P33): uma nova assinatura só depois de a folha mudar (decisão 1).
 * Regras: o mesmo instante ({@see RelogioDaFolha}) no texto selado e nas colunas da folha vista e do aceite; o IP e o
 * navegador sem UTF-8 inválido e cortados no tamanho da coluna, e a ressalva sem UTF-8 inválido e aparada
 * ({@see AssinarFolhaInput::ressalvaParaGravar()}), ANTES de selar, para o selo conferir com o que foi gravado.
 */
final class AssinarFolhaDoPontoUseCase
{
    public function __construct(
        private readonly ConteudoDaFolhaDoMes $conteudos,
        private readonly EstadoDoAceiteDaFolha $estado,
        private readonly EmissaoDaFolha $emissao,
        private readonly AceiteDaFolhaDoPontoRepository $aceites,
        private readonly SeloDaFolha $selo,
        private readonly TrilhaDoPonto $trilha,
        private readonly EntityManagerInterface $em,
        private readonly RelogioDaFolha $relogio,
    ) {
    }

    /** @throws AssinaturaDaFolhaRecusadaException */
    public function executar(AssinarFolhaInput $pedido, User $pessoa, Tenant $escritorio): AceiteDaFolhaOutput
    {
        self::exigirCompetencia($pedido);
        $agora = $this->relogio->agora();
        $folhaDeAgora = $this->conteudos->montar($escritorio, $pessoa, $pedido->ano, $pedido->mes, $agora);
        $numero = $this->numeroSePodeAssinar($escritorio, $pessoa, $folhaDeAgora, $agora);
        $this->exigirRessalvaCoerente($pedido->situacao(), $pedido->ressalvaParaGravar());

        try {
            $aceite = $this->trilha->emTransacao(fn (): AceiteDaFolhaDoPonto => $this->gravar($folhaDeAgora, $pessoa, $escritorio, $pedido, $numero, $agora));
        } catch (UniqueConstraintViolationException $corrida) {
            throw AssinaturaDaFolhaRecusadaException::jaAssinada($corrida);
        }

        return AceiteDaFolhaOutput::doAceiteGravado($aceite);
    }

    /**
     * Mês fora de 1 a 12 ou ano fora de quatro dígitos: a mesma recusa do mês que não está na folha, antes de montar a
     * folha (que não existe para esse mês).
     *
     * @throws AssinaturaDaFolhaRecusadaException
     */
    private static function exigirCompetencia(AssinarFolhaInput $pedido): void
    {
        try {
            FolhaEmitidaDoPonto::exigirCompetencia($pedido->ano, $pedido->mes);
        } catch (\InvalidArgumentException) {
            throw AssinaturaDaFolhaRecusadaException::por(AssinaturaDaFolhaRecusadaException::FORA_DA_FOLHA);
        }
    }

    /**
     * O número do aceite novo sai da MESMA leitura que decidiu "pode assinar": o número do último aceite lido mais um,
     * ou 1 sem aceite. Dois envios que leram o mesmo último aceite gravam o mesmo número, e o índice único
     * `(tenant, user, ano, mes, numero)` recusa o segundo, mesmo que o primeiro já tenha gravado antes de ele chegar à
     * gravação (um número relido dentro da transação daria o seguinte e deixaria passar os dois).
     *
     * @throws AssinaturaDaFolhaRecusadaException
     */
    private function numeroSePodeAssinar(Tenant $escritorio, User $pessoa, ConteudoDaFolha $folhaDeAgora, \DateTimeImmutable $agora): int
    {
        $estado = $this->estado->avaliar($escritorio, $pessoa, $folhaDeAgora, $agora);
        if ($estado->impedimento !== null) {
            throw AssinaturaDaFolhaRecusadaException::por($estado->impedimento);
        }

        return ($estado->numero ?? 0) + 1;
    }

    /** @throws AssinaturaDaFolhaRecusadaException */
    private function exigirRessalvaCoerente(SituacaoDoAceiteDaFolha $situacao, ?string $ressalva): void
    {
        try {
            AceiteDaFolhaDoPonto::exigirRessalvaCoerente($situacao, $ressalva);
        } catch (\DomainException $regra) {
            throw AssinaturaDaFolhaRecusadaException::ressalvaInvalida($regra);
        }
    }

    private function gravar(ConteudoDaFolha $folhaDeAgora, User $pessoa, Tenant $escritorio, AssinarFolhaInput $pedido, int $numero, \DateTimeImmutable $agora): AceiteDaFolhaDoPonto
    {
        $vista = $this->emissao->emitir($folhaDeAgora, $escritorio, $pessoa, $pessoa, $agora, null);
        $aceite = $this->aceiteSelado($vista, $numero, $pedido, $agora);
        $this->aceites->adicionar($aceite);
        $this->em->flush();

        $this->trilha->registrar($escritorio, $pessoa, AcaoDaTrilhaDoPonto::FolhaEmitida->value, DetalheDaTrilhaDoPonto::daFolhaEmitida($vista));
        $this->trilha->registrar($escritorio, $pessoa, self::acaoDa($aceite->getSituacao())->value, DetalheDaTrilhaDoPonto::doAceiteDaFolha($aceite));

        return $aceite;
    }

    /** O texto selado e a linha saem dos mesmos valores: o navegador cortado e o mesmo instante. */
    private function aceiteSelado(FolhaEmitidaDoPonto $vista, int $numero, AssinarFolhaInput $pedido, \DateTimeImmutable $agora): AceiteDaFolhaDoPonto
    {
        [$ip, $navegador] = [self::cortado($pedido->ip, AceiteDaFolhaDoPonto::LIMITE_DO_IP), self::cortado($pedido->navegador, AceiteDaFolhaDoPonto::LIMITE_DO_NAVEGADOR)];
        $situacao = $pedido->situacao();
        $ressalva = $pedido->ressalvaParaGravar();
        $canonico = DadosDoAceiteDaFolha::daAssinatura($vista, $numero, $situacao, $ressalva, $agora, $ip, $navegador)->canonico();
        $selado = $this->selo->selar($canonico);

        return new AceiteDaFolhaDoPonto($vista, $numero, $situacao, $ressalva, $agora, $ip, $navegador, $selado['hmac'], $selado['versao'], $canonico);
    }

    private static function acaoDa(SituacaoDoAceiteDaFolha $situacao): AcaoDaTrilhaDoPonto
    {
        return $situacao === SituacaoDoAceiteDaFolha::Ressalva ? AcaoDaTrilhaDoPonto::FolhaAssinadaComRessalva : AcaoDaTrilhaDoPonto::FolhaAssinada;
    }

    /** Sem UTF-8 inválido (o byte vira "?": o texto selado é JSON), aparado e cortado no tamanho da coluna. */
    private static function cortado(?string $valor, int $limite): ?string
    {
        $valor = $valor === null ? '' : trim(mb_scrub($valor, 'UTF-8'));

        return $valor === '' ? null : mb_substr($valor, 0, $limite);
    }
}
