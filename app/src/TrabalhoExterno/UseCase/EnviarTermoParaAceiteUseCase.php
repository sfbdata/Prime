<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDoTermo;
use App\TrabalhoExterno\Entity\TermoDeVoluntariado;
use App\TrabalhoExterno\Enum\StatusCurriculo;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\PoliticaDoTermo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Enviar para aceite" (`enviarTermo`, desenho 06 de 25/10, l.1134): valida os 7 obrigatórios, grava o termo como
 * `enviado` (imutável daí em diante), passa o currículo "novo" para "em análise" e avisa o voluntário (sino). O
 * escritório emissor é a sede da Central; sem sede definida, não há quem emita. O código `TV-n` nasce dentro da
 * transação do INSERT. Quem: o interno que gere o banco.
 */
final class EnviarTermoParaAceiteUseCase
{
    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly PoliticaDoTermo $politica,
        private readonly TermoDeVoluntariadoRepository $termos,
        private readonly AvisosDoTrabalhoExterno $avisos,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param array<string, mixed> $dados */
    public function executar(Contexto $ctx, string $codigoDoCurriculo, array $dados): TermoDeVoluntariado
    {
        $cv = $this->localizador->localizar($ctx, $codigoDoCurriculo);
        if (!$this->politica->podeGerar($ctx, $cv)) {
            throw new AcaoNaoPermitida('Só a Central com acesso ao banco de talentos envia o termo de um currículo que aceita voluntário.');
        }
        $campos = DadosDoTermo::deArray($dados);
        $campos->exigirObrigatorios();
        $sede = $ctx->configuracao->getSede() ?? throw new DadosInvalidos('Defina o escritório-sede da Central antes de enviar o termo.');

        $termo = $this->em->wrapInTransaction(function () use ($sede, $cv, $campos, $ctx): TermoDeVoluntariado {
            $termo = TermoDeVoluntariado::enviar($sede, $cv, $campos->campos, $this->termos->proximoCodigo(), $ctx->usuario, new \DateTimeImmutable());
            $this->termos->salvar($termo, flush: true);

            return $termo;
        });
        if ($cv->getStatus() === StatusCurriculo::Novo) {
            $cv->mudarStatus(StatusCurriculo::Analise);
        }
        $this->avisos->termoEnviado($ctx, $termo);
        $this->em->flush();

        return $termo;
    }
}
