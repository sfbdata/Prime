<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\CarimboDoAceiteNaFolha;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\ValidacaoDaFolha;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Exception\CompetenciaDaFolhaInvalidaException;
use App\Ponto\Service\ConteudoDaFolhaDoMes;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\EmissorDaFolha;
use App\Ponto\Service\EmissaoDaFolha;
use App\Ponto\Service\EstadoDoAceiteDaFolha;
use App\Ponto\Service\RelogioDaFolha;
use App\Ponto\Service\SeloDaFolha;
use App\Ponto\Service\TrilhaDoPonto;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Emite a folha do mês com código BJF (spec ponto-desenho-0811, E16, P34, P89; decisões 2, 16 e 18).
 *
 * Quem: a própria pessoa, ou o gestor que exporta a folha de outro colaborador (P89). A permissão é conferida antes,
 * no controller, como hoje (vínculo ativo e `admin.users.manage`, ou o Master); aqui chegam o escritório da sessão, a
 * pessoa da folha e quem emite.
 * Para quê: ter a folha do mês em PDF com código de validação, QR e o carimbo do aceite que vale.
 * Pré-condições: competência válida (mês de 1 a 12, ano com quatro dígitos).
 * Fluxo principal: monta a folha do mês NESTE escritório; carimba o aceite que vale, se a folha não mudou depois dele
 * (decisão 3); com o selo pronto, emite uma folha nova (código sorteado, dados canônicos e HMAC), grava a linha e, depois
 * do flush que dá o id, o evento "Folha emitida" na trilha, tudo na mesma transação; devolve o conteúdo com a validação.
 * Fluxos alternativos: sem o segredo do HMAC, devolve o conteúdo sem validação e não grava nada (decisão 16); a trilha
 * que falha desfaz a folha (e a folha que falha não deixa evento).
 * Pós-condições: com o selo, uma linha nova em `ponto_folha_emitida` e um evento "Folha emitida" na trilha do
 * escritório, por emissão. Cada PDF gerado é uma emissão nova, com código próprio (decisão 2): nada é reaproveitado.
 * Regras: o instante da emissão é um só para o texto selado e a coluna ({@see RelogioDaFolha}).
 */
final class EmitirFolhaDoPontoUseCase implements EmissorDaFolha
{
    public function __construct(
        private readonly ConteudoDaFolhaDoMes $conteudos,
        private readonly EstadoDoAceiteDaFolha $estado,
        private readonly EmissaoDaFolha $emissao,
        private readonly SeloDaFolha $selo,
        private readonly TrilhaDoPonto $trilha,
        private readonly EntityManagerInterface $em,
        private readonly RelogioDaFolha $relogio,
    ) {
    }

    /**
     * @param ?User $emissor quem gera o PDF (a pessoa ou o gestor); autor do evento na trilha
     *
     * @throws CompetenciaDaFolhaInvalidaException competência fora do calendário
     */
    public function executar(Tenant $escritorio, User $pessoa, int $ano, int $mes, ?User $emissor): ConteudoDaFolha
    {
        FolhaEmitidaDoPonto::exigirCompetencia($ano, $mes);
        $agora = $this->relogio->agora();
        $conteudo = $this->conteudos->montar($escritorio, $pessoa, $ano, $mes, $agora);
        $aceite = $this->estado->aceiteQueCarimba($escritorio, $pessoa, $conteudo);
        $conteudo = $conteudo->comCarimbo($aceite === null ? null : CarimboDoAceiteNaFolha::doAceite($aceite));
        if (!$this->selo->disponivel()) {
            return $conteudo;
        }

        $folha = $this->trilha->emTransacao(fn (): FolhaEmitidaDoPonto => $this->gravar($conteudo, $escritorio, $pessoa, $emissor, $agora, $aceite));

        return $conteudo->comValidacao(ValidacaoDaFolha::daFolha($folha));
    }

    private function gravar(ConteudoDaFolha $conteudo, Tenant $escritorio, User $pessoa, ?User $emissor, \DateTimeImmutable $agora, ?AceiteDaFolhaDoPonto $aceite): FolhaEmitidaDoPonto
    {
        $folha = $this->emissao->emitir($conteudo, $escritorio, $pessoa, $emissor, $agora, $aceite);
        $this->em->flush();
        $this->trilha->registrar($escritorio, $emissor, AcaoDaTrilhaDoPonto::FolhaEmitida->value, DetalheDaTrilhaDoPonto::daFolhaEmitida($folha));

        return $folha;
    }
}
