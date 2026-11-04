(function () {
  'use strict';

  var button = document.getElementById('not-found-meeting');
  var anders = document.getElementById('not-found-anders');
  var bo = document.getElementById('not-found-bo');
  var scene = document.querySelector('.not-found-scene');
  if (!button || !anders || !bo || !scene) return;

  var meetings = [
    ['Hvad hvis vi hæver prisen?', 'Anders. Der er ikke noget produkt.'],
    ['Jeg opretter en support-ticket.', 'Fedt. Jeg starter en ny SaaS imens.'],
    ['Er det her så negativ vækst?', 'Vi kalder det en slankere portefølje.'],
    ['Kan vi sælge den som en MVP?', 'Minimum Viable Page. Meget minimum.'],
    ['Vi skal nok lave en win-back-kampagne.', 'Kære side. Vi savner dig allerede.'],
    ['Skal vi ikke bare gå til forsiden?', 'God idé. Men først: 45 minutters podcast.']
  ];
  var meeting = 0;
  button.hidden = false;
  button.addEventListener('click', function () {
    var lines = meetings[meeting % meetings.length];
    anders.textContent = lines[0];
    bo.textContent = lines[1];
    meeting += 1;
    button.firstChild.textContent = 'Ét krisemøde mere? ';
    scene.classList.remove('is-meeting');
    // Restart the short portrait animation, including on consecutive clicks.
    void scene.offsetWidth;
    scene.classList.add('is-meeting');
  });
}());
