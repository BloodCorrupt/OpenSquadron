const fs = require('fs'); 
const html = fs.readFileSync('c:/Users/Bloodtek/Documents/dev/OpenSquadron/templates/whatsapp_bot_manager/index.html.twig', 'utf8'); 
const lines = html.split('\n'); 
let depth = 0; 
for(let i=0; i<lines.length; i++) { 
    const l = lines[i]; 
    if(l.includes('class="manager-content-pane"') || l.includes('class="manager-content-pane is-active"')) { 
        console.log('Pane at line ' + (i+1) + ' starts with depth: ' + depth); 
    } 
    for(const m of l.matchAll(/<\/?div\b/gi)) { 
        if(m[0].toLowerCase() === '<div') depth++; 
        else depth--; 
    } 
} 
console.log('Final depth:', depth);
