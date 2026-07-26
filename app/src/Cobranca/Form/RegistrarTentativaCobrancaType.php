<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\RegistrarTentativaCobrancaInput;
use App\Cobranca\Enum\CanalContato;
use App\Cobranca\Enum\ResultadoContato;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Registrar um CONTATO de cobrança no histórico do Caso. `casoId` vem da rota; `dataContato` é
 * pré-preenchida com "agora" pelo controller. A única escrita é o evento de histórico.
 */
final class RegistrarTentativaCobrancaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dataContato', DateTimeType::class, [
                'label' => 'Data e hora do contato',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('canal', EnumType::class, [
                'class' => CanalContato::class,
                'label' => 'Canal',
                'placeholder' => 'Selecione o canal',
                'choice_label' => fn (CanalContato $c) => $c->label(),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('resultado', EnumType::class, [
                'class' => ResultadoContato::class,
                // Só as opções selecionáveis (sem "Prometeu pagar" — ajuste 2026-07): ninguém escolhe
                // esse resultado em contato novo, mas contatos antigos gravados com ele continuam
                // renderizando via label() no histórico.
                'choices' => ResultadoContato::selecionaveis(),
                'label' => 'Resultado',
                'choice_label' => fn (ResultadoContato $r) => $r->label(),
                'attr' => ['class' => 'form-select'],
            ])
            // O relato É narrativo e a SPEC UX §11 pediria editor rico aqui — mas ele fica FORA desta
            // entrega, de propósito. Ligar a barra sem mais nada guardaria HTML CRU no banco: este
            // UseCase concatena a observação direto na descrição do evento, sem passar pelo
            // `SanitizadorTextoRico::limpar()` que a anotação usa na escrita. Sanitizar aqui exigiria
            // mexer no UseCase (domínio) e a descrição ainda é lida pela Central de Acompanhamento
            // (`cobranca/central/_lista_eventos.html.twig`), que está em produção e escapa o valor —
            // todo contato novo passaria a mostrar `<p>` literal numa tela fora do escopo autorizado.
            // Follow-up registrado; ver SPEC §2 (timebox corta o que exige alteração estrutural).
            ->add('observacao', TextareaType::class, [
                'label' => 'Relato do Atendimento (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrarTentativaCobrancaInput::class,
        ]);
    }
}
