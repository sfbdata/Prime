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
 * Pré-condições: a função `ponto-aceite` vale e o selo da folha está pronto; o mês está fechado pelo relógio do
 * servidor; a competência está na lista da folha dela (início da contagem neste escritório); não há aceite que valha
 * (sem aceite, ou a folha mudou depois do último); na ressalva, texto com até 1.000 caracteres.
 * Fluxo principal: monta a folha de agora; confere as pré-condições ({@see EstadoDoAceiteDaFolha}); numa transação,
 * emite a folha vista (código BJF, decisão 18), grava o aceite com o número seguinte e selado com HMAC (decisão 14),
 * dá o flush único e só então grava na trilha "Folha emitida" e "Folha assinada" ou "Folha assinada com ressalva" (a
 * trilha guarda os ids; a ressalva não entra nela).
 * Fluxos alternativos: cada pré-condição que falha é uma {@see AssinaturaDaFolhaRecusadaException} e nada é gravado;
 * dois envios ao mesmo tempo recebem o mesmo número e o índice único recusa o segundo ("Esta folha já foi assinada.");
 * a trilha que falha desfaz a folha vista e o aceite.
 * Pós-condições: uma folha emitida nova (a vista), um aceite novo que passa a valer e os dois eventos na trilha do
 * escritório. Não há desfazer (P33): uma nova assinatura só depois de a folha mudar (decisão 1).
 * Regras: o mesmo instante ({@see RelogioDaFolha}) no texto selado e nas colunas da folha vista e do aceite; o navegador
 * cortado no tamanho da coluna e a ressalva aparada ANTES de selar, para o selo conferir com o que foi gravado.
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
        $agora = $this->relogio->agora();
        $folhaDeAgora = $this->conteudos->montar($escritorio, $pessoa, $pedido->ano, $pedido->mes, $agora);
        $this->exigirQuePodeAssinar($escritorio, $pessoa, $folhaDeAgora, $agora);
        $situacao = $pedido->situacao();
        $ressalva = $pedido->ressalvaParaGravar();
        $this->exigirRessalvaCoerente($situacao, $ressalva);

        try {
            $aceite = $this->trilha->emTransacao(fn (): AceiteDaFolhaDoPonto => $this->gravar($folhaDeAgora, $pessoa, $escritorio, $pedido, $agora));
        } catch (UniqueConstraintViolationException $corrida) {
            throw AssinaturaDaFolhaRecusadaException::jaAssinada($corrida);
        }

        return AceiteDaFolhaOutput::doAceiteGravado($aceite);
    }

    /** @throws AssinaturaDaFolhaRecusadaException */
    private function exigirQuePodeAssinar(Tenant $escritorio, User $pessoa, ConteudoDaFolha $folhaDeAgora, \DateTimeImmutable $agora): void
    {
        $impedimento = $this->estado->avaliar($escritorio, $pessoa, $folhaDeAgora, $agora)->impedimento;
        if ($impedimento !== null) {
            throw AssinaturaDaFolhaRecusadaException::por($impedimento);
        }
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

    private function gravar(ConteudoDaFolha $folhaDeAgora, User $pessoa, Tenant $escritorio, AssinarFolhaInput $pedido, \DateTimeImmutable $agora): AceiteDaFolhaDoPonto
    {
        $vista = $this->emissao->emitir($folhaDeAgora, $escritorio, $pessoa, $pessoa, $agora, null);
        $numero = $this->aceites->proximoNumero($escritorio, $pessoa, $folhaDeAgora->ano, $folhaDeAgora->mes);
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

    private static function cortado(?string $valor, int $limite): ?string
    {
        $valor = $valor === null ? '' : trim($valor);

        return $valor === '' ? null : mb_substr($valor, 0, $limite);
    }
}
