import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import * as encoding from '../../yjs/node_modules/lib0/encoding.js'
import * as decoding from '../../yjs/node_modules/lib0/decoding.js'
import * as prng from '../../yjs/node_modules/lib0/prng.js'
import * as Y from '../../yjs/src/index.js'
import { ContentAny, readContentAny } from '../../yjs/src/structs/ContentAny.js'
import { ContentBinary, readContentBinary } from '../../yjs/src/structs/ContentBinary.js'
import { ContentDeleted, readContentDeleted } from '../../yjs/src/structs/ContentDeleted.js'
import { ContentDoc, readContentDoc } from '../../yjs/src/structs/ContentDoc.js'
import { ContentEmbed, readContentEmbed } from '../../yjs/src/structs/ContentEmbed.js'
import { ContentFormat, readContentFormat } from '../../yjs/src/structs/ContentFormat.js'
import { ContentJSON, readContentJSON } from '../../yjs/src/structs/ContentJSON.js'
import { ContentString, readContentString } from '../../yjs/src/structs/ContentString.js'
import { ContentType, readContentType } from '../../yjs/src/structs/ContentType.js'
import { GC } from '../../yjs/src/structs/GC.js'
import { Skip } from '../../yjs/src/structs/Skip.js'
import { YArray } from '../../yjs/src/types/YArray.js'
import { YMap } from '../../yjs/src/types/YMap.js'
import { YText } from '../../yjs/src/types/YText.js'
import { YXmlElement } from '../../yjs/src/types/YXmlElement.js'
import { YXmlFragment } from '../../yjs/src/types/YXmlFragment.js'
import { YXmlHook } from '../../yjs/src/types/YXmlHook.js'
import { YXmlText } from '../../yjs/src/types/YXmlText.js'
import {
  DeleteItem as YDeleteItem,
  DeleteSet as YDeleteSet,
  readDeleteSet as yReadDeleteSet,
  writeDeleteSet as yWriteDeleteSet
} from '../../yjs/src/utils/DeleteSet.js'
import { readID as yReadID, writeID as yWriteID } from '../../yjs/src/utils/ID.js'
import { DSEncoderV2 } from '../../yjs/src/utils/UpdateEncoder.js'
import { DSDecoderV2 } from '../../yjs/src/utils/UpdateDecoder.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const fixturesDir = path.join(__dirname, '..', 'tests', 'fixtures')

const hex = buf => Buffer.from(buf.buffer, buf.byteOffset, buf.byteLength).toString('hex')

const descriptor = value => {
  if (value === undefined) {
    return { type: 'undefined' }
  }
  if (value === null) {
    return { type: 'null' }
  }
  if (typeof value === 'bigint') {
    return { type: 'bigint', value: value.toString() }
  }
  if (typeof value === 'number') {
    if (Number.isNaN(value)) {
      return { type: 'number', value: 'NaN' }
    }
    if (value === Infinity) {
      return { type: 'number', value: 'Infinity' }
    }
    if (value === -Infinity) {
      return { type: 'number', value: '-Infinity' }
    }
    if (Object.is(value, -0)) {
      return { type: 'number', value: '-0' }
    }
    return { type: 'number', value }
  }
  if (typeof value === 'string') {
    return { type: 'string', value }
  }
  if (typeof value === 'boolean') {
    return { type: 'boolean', value }
  }
  if (value instanceof Uint8Array) {
    return { type: 'uint8array', value: Array.from(value) }
  }
  if (Array.isArray(value)) {
    return { type: 'array', value: value.map(descriptor) }
  }
  return { type: 'object', value: Object.keys(value).map(key => [key, descriptor(value[key])]) }
}

const materialize = desc => {
  switch (desc.type) {
    case 'undefined':
      return undefined
    case 'null':
      return null
    case 'bigint':
      return BigInt(desc.value)
    case 'number':
      if (desc.value === 'NaN') return Number.NaN
      if (desc.value === 'Infinity') return Infinity
      if (desc.value === '-Infinity') return -Infinity
      if (desc.value === '-0') return -0
      return desc.value
    case 'string':
      return desc.value
    case 'boolean':
      return desc.value
    case 'uint8array':
      return new Uint8Array(desc.value)
    case 'array':
      return desc.value.map(materialize)
    case 'object': {
      const obj = {}
      for (const [key, value] of desc.value) {
        obj[key] = materialize(value)
      }
      return obj
    }
  }
  throw new Error(`Unknown descriptor type: ${desc.type}`)
}

const encodeCase = (writer, input) => {
  const value = materialize(input)
  const encoder = encoding.createEncoder()
  switch (writer) {
    case 'writeVarUint':
      encoding.writeVarUint(encoder, value)
      break
    case 'writeVarInt':
      encoding.writeVarInt(encoder, value)
      break
    case 'writeVarString':
      encoding.writeVarString(encoder, value)
      break
    case 'writeFloat32':
      encoding.writeFloat32(encoder, value)
      break
    case 'writeFloat64':
      encoding.writeFloat64(encoder, value)
      break
    case 'writeBigInt64':
      encoding.writeBigInt64(encoder, value)
      break
    case 'writeUint8Array':
      encoding.writeUint8Array(encoder, value)
      break
    case 'writeVarUint8Array':
      encoding.writeVarUint8Array(encoder, value)
      break
    case 'writeAny':
      encoding.writeAny(encoder, value)
      break
    default:
      throw new Error(`Unknown writer: ${writer}`)
  }

  const bytes = encoding.toUint8Array(encoder)
  const decoder = decoding.createDecoder(bytes)
  let decoded
  switch (writer) {
    case 'writeVarUint':
      decoded = decoding.readVarUint(decoder)
      break
    case 'writeVarInt':
      decoded = decoding.readVarInt(decoder)
      break
    case 'writeVarString':
      decoded = decoding.readVarString(decoder)
      break
    case 'writeFloat32':
      decoded = decoding.readFloat32(decoder)
      break
    case 'writeFloat64':
      decoded = decoding.readFloat64(decoder)
      break
    case 'writeBigInt64':
      decoded = decoding.readBigInt64(decoder)
      break
    case 'writeUint8Array':
      decoded = decoding.readUint8Array(decoder, value.byteLength)
      break
    case 'writeVarUint8Array':
      decoded = decoding.readVarUint8Array(decoder)
      break
    case 'writeAny':
      decoded = decoding.readAny(decoder)
      break
  }

  return {
    name: `${writer} ${JSON.stringify(input)}`,
    writer,
    input,
    decoded: descriptor(decoded),
    hex: hex(bytes)
  }
}

const number = value => ({ type: 'number', value })
const specialNumber = value => ({ type: 'number', value })
const string = value => ({ type: 'string', value })
const bool = value => ({ type: 'boolean', value })
const nil = () => ({ type: 'null' })
const undef = () => ({ type: 'undefined' })
const bigint = value => ({ type: 'bigint', value })
const uint8array = value => ({ type: 'uint8array', value })
const array = value => ({ type: 'array', value })
const object = value => ({ type: 'object', value })
const id = (client, clock) => ({ type: 'id', client, clock })

const cases = [
  ...[0, 1, 2, 63, 64, 127, 128, 255, 256, 16383, 16384, 4294967295, Number.MAX_SAFE_INTEGER].map(value => encodeCase('writeVarUint', number(value))),
  ...[specialNumber('-0'), number(0), number(1), number(-1), number(63), number(64), number(-64), number(-65), number(2147483647), number(-2147483647)].map(value => encodeCase('writeVarInt', value)),
  ...['', 'hello', 'h\u00e9llo', '\ud83d\ude0a', 'line\nbreak'].map(value => encodeCase('writeVarString', string(value))),
  ...[specialNumber('-0'), number(0), number(1.5), number(-2.25), specialNumber('Infinity'), specialNumber('-Infinity')].map(value => encodeCase('writeFloat32', value)),
  ...[number(Math.PI), number(0.1), number(-123456.789), specialNumber('NaN'), specialNumber('Infinity'), specialNumber('-Infinity')].map(value => encodeCase('writeFloat64', value)),
  ...['0', '1', '-1', '9223372036854775807', '-9223372036854775808'].map(value => encodeCase('writeBigInt64', bigint(value))),
  ...[[0, 1, 2, 255], [], [128, 129, 130]].map(value => encodeCase('writeUint8Array', uint8array(value))),
  ...[[0, 1, 2, 255], [], [128, 129, 130]].map(value => encodeCase('writeVarUint8Array', uint8array(value))),
  ...[
    undef(),
    nil(),
    bool(true),
    bool(false),
    string('any-string'),
    string('snow \u2603'),
    specialNumber('-0'),
    number(42),
    number(-42),
    number(2147483647),
    number(2147483648),
    number(2147483649),
    number(1.5),
    number(0.1),
    specialNumber('NaN'),
    bigint('-9223372036854775808'),
    uint8array([5, 6, 7]),
    array([number(1), string('two'), nil(), undef(), uint8array([9])]),
    object([
      ['alpha', number(1)],
      ['beta', array([bool(true), string('ok')])],
      ['gamma', object([['nested', nil()]])]
    ]),
    object([])
  ].map(value => encodeCase('writeAny', value))
]

const makePrngFixture = () => {
  const seed = 1337
  const nextGen = prng.create(seed)
  const next = Array.from({ length: 12 }, () => nextGen.next())

  const helperGen = prng.create(seed)
  const helperOps = [
    { op: 'bool', value: prng.bool(helperGen) },
    { op: 'int32', args: [-100, 100], value: prng.int32(helperGen, -100, 100) },
    { op: 'uint32', args: [0, 100000], value: prng.uint32(helperGen, 0, 100000) },
    { op: 'word', args: [3, 8], value: prng.word(helperGen, 3, 8) },
    { op: 'oneOf', args: [['red', 'green', 'blue', 'gold']], value: prng.oneOf(helperGen, ['red', 'green', 'blue', 'gold']) },
    { op: 'int32', args: [-2147483648, 2147483647], value: prng.int32(helperGen, -2147483648, 2147483647) },
    { op: 'uint32', args: [-2147483648, 2147483647], value: prng.uint32(helperGen, -2147483648, 2147483647) }
  ]

  return { seed, next, helperOps }
}

const captureYjsScenario = (name, clientID, apply) => {
  const doc = new Y.Doc({ guid: `y-php-${name}` })
  doc.clientID = clientID
  apply(doc)

  return {
    name,
    json: doc.toJSON(),
    updateHex: hex(Y.encodeStateAsUpdate(doc)),
    updateV2Hex: hex(Y.encodeStateAsUpdateV2(doc)),
    stateVectorHex: hex(Y.encodeStateVector(doc)),
    snapshotHex: hex(Y.encodeSnapshot(Y.snapshot(doc))),
    snapshotV2Hex: hex(Y.encodeSnapshotV2(Y.snapshot(doc)))
  }
}

const makeYjsScenarioFixtures = () => ({
  source: 'yjs/src/index.js',
  scenarios: [
    captureYjsScenario('array-basic', 1, doc => {
      doc.getArray('array').insert(0, ['hi', 1, true])
    }),
    captureYjsScenario('map-nested-array', 2, doc => {
      const map = doc.getMap('map')
      const array = new Y.Array()
      map.set('name', 'Ada')
      map.set('items', array)
      array.insert(0, [1, 2, 3])
    }),
    captureYjsScenario('text-format', 3, doc => {
      const text = doc.getText('text')
      text.insert(0, 'hello')
      text.format(0, 5, { bold: true })
    })
  ]
})

const updateMetaDescriptor = meta => ({
  from: Array.from(meta.from.entries()),
  to: Array.from(meta.to.entries())
})

const captureUpdateUtilityCase = (name, setup) => {
  const updates = []
  const doc = new Y.Doc({ gc: false, guid: `y-php-update-utils-${name}` })
  doc.clientID = 10
  doc.on('update', update => updates.push(update))
  setup(doc)
  const merged = Y.mergeUpdates(updates)
  const firstMerged = Y.mergeUpdates(updates.slice(0, 1))
  const firstStateVector = Y.encodeStateVectorFromUpdate(firstMerged)
  const diffFromFirst = Y.diffUpdate(merged, firstStateVector)
  return {
    name,
    updatesHex: updates.map(hex),
    finalUpdateHex: hex(Y.encodeStateAsUpdate(doc)),
    mergedHex: hex(merged),
    stateVectorFromMergedHex: hex(Y.encodeStateVectorFromUpdate(merged)),
    finalStateVectorHex: hex(Y.encodeStateVector(doc)),
    firstStateVectorHex: hex(firstStateVector),
    diffFromFirstHex: hex(diffFromFirst),
    parseMergedMeta: updateMetaDescriptor(Y.parseUpdateMeta(merged)),
    snapshotHex: hex(Y.encodeSnapshot(Y.snapshot(doc)))
  }
}

const makeUpdateUtilityFixtures = () => ({
  source: 'yjs/src/utils/updates.js',
  cases: [
    captureUpdateUtilityCase('array-overlap', doc => {
      const array = doc.getArray('array')
      array.insert(0, [1])
      array.insert(0, [2])
      array.insert(0, [3])
      array.delete(1, 1)
    }),
    captureUpdateUtilityCase('text-format-delete', doc => {
      const text = doc.getText('text')
      text.insert(0, 'hello')
      text.format(0, 5, { bold: true })
      text.delete(1, 2)
      text.insert(1, 'i')
    }),
    captureUpdateUtilityCase('map-nested-type', doc => {
      const map = doc.getMap('map')
      const nested = new Y.Array()
      map.set('nested', nested)
      nested.insert(0, ['a', 'b'])
      map.set('flag', true)
      nested.delete(0, 1)
    })
  ]
})

const captureUpdateUtilityV2Case = (name, setup) => {
  const updatesV1 = []
  const updatesV2 = []
  const doc = new Y.Doc({ gc: false, guid: `y-php-update-utils-v2-${name}` })
  doc.clientID = 10
  doc.on('update', update => updatesV1.push(update))
  doc.on('updateV2', update => updatesV2.push(update))
  setup(doc)
  const mergedV2 = Y.mergeUpdatesV2(updatesV2)
  const firstMergedV2 = Y.mergeUpdatesV2(updatesV2.slice(0, 1))
  const firstStateVectorV2 = Y.encodeStateVectorFromUpdateV2(firstMergedV2)
  const diffFromFirstV2 = Y.diffUpdateV2(mergedV2, firstStateVectorV2)
  const finalUpdateV1 = Y.encodeStateAsUpdate(doc)
  const finalUpdateV2 = Y.encodeStateAsUpdateV2(doc)
  return {
    name,
    updatesV1Hex: updatesV1.map(hex),
    updatesV2Hex: updatesV2.map(hex),
    finalUpdateV1Hex: hex(finalUpdateV1),
    finalUpdateV2Hex: hex(finalUpdateV2),
    mergedV2Hex: hex(mergedV2),
    stateVectorFromMergedV2Hex: hex(Y.encodeStateVectorFromUpdateV2(mergedV2)),
    finalStateVectorHex: hex(Y.encodeStateVector(doc)),
    firstStateVectorV2Hex: hex(firstStateVectorV2),
    diffFromFirstV2Hex: hex(diffFromFirstV2),
    parseMergedMetaV2: updateMetaDescriptor(Y.parseUpdateMetaV2(mergedV2)),
    snapshotV2Hex: hex(Y.encodeSnapshotV2(Y.snapshot(doc))),
    convertedFinalV1ToV2Hex: hex(Y.convertUpdateFormatV1ToV2(finalUpdateV1)),
    convertedFinalV2ToV1Hex: hex(Y.convertUpdateFormatV2ToV1(finalUpdateV2)),
    obfuscatedFinalV2Hex: hex(Y.obfuscateUpdateV2(finalUpdateV2))
  }
}

const makeUpdateUtilityV2Fixtures = () => ({
  source: 'yjs/src/utils/updates.js',
  cases: [
    captureUpdateUtilityV2Case('array-overlap', doc => {
      const array = doc.getArray('array')
      array.insert(0, [1])
      array.insert(0, [2])
      array.insert(0, [3])
      array.delete(1, 1)
    }),
    captureUpdateUtilityV2Case('text-format-delete', doc => {
      const text = doc.getText('text')
      text.insert(0, 'hello')
      text.format(0, 5, { bold: true })
      text.delete(1, 2)
      text.insert(1, 'i')
    }),
    captureUpdateUtilityV2Case('map-nested-type', doc => {
      const map = doc.getMap('map')
      const nested = new Y.Array()
      map.set('nested', nested)
      nested.insert(0, ['a', 'b'])
      map.set('flag', true)
      nested.delete(0, 1)
    })
  ]
})

const runYArrayConvergenceCase = (seed, iterations, users) => {
  const gen = prng.create(seed)
  const docs = Array.from({ length: users }, (_, i) => {
    const doc = new Y.Doc({ guid: `y-php-yarray-${seed}-${i}` })
    doc.clientID = i + 1
    return doc
  })
  const operations = []
  let uniqueNumber = 0

  for (let i = 0; i < iterations; i++) {
    const user = prng.int32(gen, 0, users - 1)
    const doc = docs[user]
    const yarray = doc.getArray('array')
    const op = prng.int32(gen, 0, 4)

    if (op === 0) {
      const value = uniqueNumber++
      const len = prng.int32(gen, 1, 4)
      const content = Array.from({ length: len }, () => value)
      const pos = prng.int32(gen, 0, yarray.length)
      yarray.insert(pos, content)
      operations.push({ op: 'insertNumbers', user, pos, content })
    } else if (op === 1) {
      const pos = prng.int32(gen, 0, yarray.length)
      yarray.insert(pos, [new Y.Array()])
      yarray.get(pos).insert(0, [1, 2, 3, 4])
      operations.push({ op: 'insertArray', user, pos, content: [1, 2, 3, 4] })
    } else if (op === 2) {
      const pos = prng.int32(gen, 0, yarray.length)
      yarray.insert(pos, [new Y.Map()])
      const map = yarray.get(pos)
      map.set('someprop', 42)
      map.set('someprop', 43)
      map.set('someprop', 44)
      operations.push({ op: 'insertMap', user, pos, writes: [['someprop', 42], ['someprop', 43], ['someprop', 44]] })
    } else if (op === 3) {
      const pos = prng.int32(gen, 0, yarray.length)
      yarray.insert(pos, [null])
      operations.push({ op: 'insertNull', user, pos })
    } else if (yarray.length > 0) {
      const pos = prng.int32(gen, 0, yarray.length - 1)
      const len = prng.int32(gen, 1, Math.min(2, yarray.length - pos))
      yarray.delete(pos, len)
      operations.push({ op: 'delete', user, pos, len })
    } else {
      operations.push({ op: 'noop', user })
    }
  }

  const localUpdates = docs.map(doc => Y.encodeStateAsUpdate(doc))
  docs.forEach((doc, docIndex) => {
    localUpdates.forEach((update, updateIndex) => {
      if (docIndex !== updateIndex) {
        Y.applyUpdate(doc, update)
      }
    })
  })

  return {
    name: `seed-${seed}`,
    seed,
    users,
    iterations,
    operations,
    json: docs.map(doc => doc.getArray('array').toJSON()),
    updateHexes: docs.map(doc => hex(Y.encodeStateAsUpdate(doc))),
    updateV2Hexes: docs.map(doc => hex(Y.encodeStateAsUpdateV2(doc))),
    stateVectorHexes: docs.map(doc => hex(Y.encodeStateVector(doc)))
  }
}

const makeYArrayConvergenceFixtures = () => ({
  source: 'yjs/src/types/YArray.js + yjs/tests/y-array.tests.js',
  cases: [6, 40, 42, 43, 44, 45, 46, 300, 400, 500].map(seed => runYArrayConvergenceCase(seed, 80, 3))
})

const runYMapConvergenceCase = (seed, iterations, users) => {
  const gen = prng.create(seed)
  const docs = Array.from({ length: users }, (_, i) => {
    const doc = new Y.Doc({ guid: `y-php-ymap-${seed}-${i}` })
    doc.clientID = i + 1
    return doc
  })
  const operations = []

  for (let i = 0; i < iterations; i++) {
    const user = prng.int32(gen, 0, users - 1)
    const doc = docs[user]
    const ymap = doc.getMap('map')
    const op = prng.int32(gen, 0, 2)
    const key = prng.oneOf(gen, ['one', 'two'])

    if (op === 0) {
      const value = prng.utf16String(gen)
      ymap.set(key, value)
      operations.push({ op: 'setString', user, key, value })
    } else if (op === 1) {
      const typeName = prng.oneOf(gen, ['YArray', 'YMap'])
      if (typeName === 'YArray') {
        ymap.set(key, new Y.Array())
        ymap.get(key).insert(0, [1, 2, 3, 4])
        operations.push({ op: 'setArray', user, key, content: [1, 2, 3, 4] })
      } else {
        ymap.set(key, new Y.Map())
        ymap.get(key).set('deepkey', 'deepvalue')
        operations.push({ op: 'setMap', user, key, entries: [['deepkey', 'deepvalue']] })
      }
    } else {
      ymap.delete(key)
      operations.push({ op: 'delete', user, key })
    }
  }

  const localUpdates = docs.map(doc => Y.encodeStateAsUpdate(doc))
  docs.forEach((doc, docIndex) => {
    localUpdates.forEach((update, updateIndex) => {
      if (docIndex !== updateIndex) {
        Y.applyUpdate(doc, update)
      }
    })
  })

  return {
    name: `seed-${seed}`,
    seed,
    users,
    iterations,
    operations,
    json: docs.map(doc => doc.getMap('map').toJSON()),
    updateHexes: docs.map(doc => hex(Y.encodeStateAsUpdate(doc))),
    updateV2Hexes: docs.map(doc => hex(Y.encodeStateAsUpdateV2(doc))),
    stateVectorHexes: docs.map(doc => hex(Y.encodeStateVector(doc)))
  }
}

const makeYMapConvergenceFixtures = () => ({
  source: 'yjs/src/types/YMap.js + yjs/tests/y-map.tests.js',
  cases: [10, 40, 42, 43, 44, 45, 46, 300, 400, 500].map(seed => runYMapConvergenceCase(seed, 80, 3))
})

/**
 * A client edits inside a nested subtree while the server concurrently
 * deletes (and garbage-collects) that subtree. Applying the client's update
 * then makes `Item.getMissing` resolve origin / rightOrigin / parent
 * references onto GC structs (or a ContentDeleted parent), and the incoming
 * items must be discarded as GCs — not crash. Each case exercises one lane.
 */
const runGcResolutionCase = (name, edit) => {
  const server = new Y.Doc({ guid: `y-php-gc-${name}-server` })
  server.clientID = 1
  const blocks = server.getArray('blocks')
  const block = new Y.Map()
  blocks.insert(0, [block])
  const meta = new Y.Map()
  block.set('meta', meta)
  const text = new Y.Text()
  block.set('text', text)
  text.insert(0, 'hello')

  const client = new Y.Doc({ guid: `y-php-gc-${name}-client` })
  client.clientID = 2
  Y.applyUpdate(client, Y.encodeStateAsUpdate(server))
  const baseline = Y.encodeStateVector(client)

  edit(client.getArray('blocks').get(0))
  const clientUpdate = Y.encodeStateAsUpdate(client, baseline)
  const clientUpdateV2 = Y.encodeStateAsUpdateV2(client, baseline)

  server.getArray('blocks').delete(0)
  Y.applyUpdate(server, clientUpdate)

  return {
    name,
    clientUpdateHex: hex(clientUpdate),
    clientUpdateV2Hex: hex(clientUpdateV2),
    json: server.getArray('blocks').toJSON(),
    updateHex: hex(Y.encodeStateAsUpdate(server)),
    updateV2Hex: hex(Y.encodeStateAsUpdateV2(server)),
    stateVectorHex: hex(Y.encodeStateVector(server))
  }
}

const makeGcResolutionFixtures = () => ({
  source: 'yjs/src/structs/Item.js (getMissing) + yjs/src/utils/StructStore.js',
  cases: [
    // parent -> Item whose content is ContentDeleted (`.type` is undefined).
    runGcResolutionCase('parent-content-deleted', block => {
      block.set('newKey', 'v')
    }),
    // parent -> GC struct (getItem returns the GC).
    runGcResolutionCase('parent-gc', block => {
      block.get('meta').set('k', 'v')
    }),
    // origin -> GC range (getItemCleanEnd returns the GC).
    runGcResolutionCase('origin-gc', block => {
      block.get('text').insert(5, '!')
    }),
    // origin null, rightOrigin -> GC range (getItemCleanStart returns the GC).
    runGcResolutionCase('rightorigin-gc', block => {
      block.get('text').insert(0, '>')
    })
  ]
})

const ytextAttrs = [
  { bold: true },
  { bold: null },
  { italic: true },
  { color: 'red' },
  { falsy: false },
  { meta: { level: '1' } }
]

const captureYTextScenario = (name, clientID, apply) => {
  const doc = new Y.Doc({ guid: `y-php-ytext-${name}` })
  doc.clientID = clientID
  apply(doc.getText('text'), doc)
  return {
    name,
    delta: doc.getText('text').toDelta(),
    string: doc.getText('text').toString(),
    updateHex: hex(Y.encodeStateAsUpdate(doc)),
    updateV2Hex: hex(Y.encodeStateAsUpdateV2(doc)),
    stateVectorHex: hex(Y.encodeStateVector(doc)),
    snapshotHex: hex(Y.encodeSnapshot(Y.snapshot(doc))),
    snapshotV2Hex: hex(Y.encodeSnapshotV2(Y.snapshot(doc)))
  }
}

const makeYTextFixtures = () => ({
  source: 'yjs/src/types/YText.js',
  scenarios: [
    captureYTextScenario('formatted-runs', 11, text => {
      text.insert(0, 'hello world')
      text.format(0, 5, { bold: true })
      text.format(6, 5, { color: 'red' })
      text.delete(5, 1)
      text.insert(5, ' ')
    }),
    captureYTextScenario('delta-embed', 12, text => {
      text.applyDelta([
        { insert: 'a', attributes: { bold: true } },
        { insert: { image: 'imageSrc.png' }, attributes: { width: 100 } },
        { insert: 'b', attributes: { bold: true } }
      ])
    }),
    captureYTextScenario('format-cleanup', 13, text => {
      text.insert(0, 'abcdef')
      text.format(0, 6, { bold: true })
      text.format(2, 2, { bold: null })
      text.delete(1, 4)
      text.insert(1, 'ZZ', { italic: true })
    }),
    captureYTextScenario('map-attributes', 14, text => {
      text.setAttribute('lang', 'en')
      text.setAttribute('block', { id: 'p1' })
      text.insert(0, 'paragraph\n', { block: { id: 'p1' } })
    })
  ]
})

const runYTextConvergenceCase = (seed, iterations, users) => {
  const gen = prng.create(seed)
  const docs = Array.from({ length: users }, (_, i) => {
    const doc = new Y.Doc({ guid: `y-php-ytext-${seed}-${i}` })
    doc.clientID = i + 1
    return doc
  })
  const operations = []
  let unique = 0

  for (let i = 0; i < iterations; i++) {
    const user = prng.int32(gen, 0, users - 1)
    const doc = docs[user]
    const text = doc.getText('text')
    const op = prng.int32(gen, 0, 4)

    if (op === 0 || text.length === 0) {
      const value = prng.oneOf(gen, ['a', 'b', 'c', ' ', '\n', `w${unique++}`])
      const pos = prng.int32(gen, 0, text.length)
      text.insert(pos, value)
      operations.push({ op: 'insertText', user, pos, value })
    } else if (op === 1) {
      const pos = prng.int32(gen, 0, text.length)
      const embed = { image: `image-${unique++}.png` }
      const attrs = prng.oneOf(gen, [{ width: 100 }, { width: 200, bold: true }, {}])
      text.insertEmbed(pos, embed, attrs)
      operations.push({ op: 'insertEmbed', user, pos, embed, attrs })
    } else if (op === 2) {
      const pos = prng.int32(gen, 0, text.length - 1)
      const len = prng.int32(gen, 1, Math.min(3, text.length - pos))
      const attrs = prng.oneOf(gen, ytextAttrs)
      text.format(pos, len, attrs)
      operations.push({ op: 'format', user, pos, len, attrs })
    } else if (op === 3) {
      const pos = prng.int32(gen, 0, text.length - 1)
      const len = prng.int32(gen, 1, Math.min(3, text.length - pos))
      text.delete(pos, len)
      operations.push({ op: 'delete', user, pos, len })
    } else {
      const delta = [
        { retain: prng.int32(gen, 0, text.length) },
        { insert: prng.oneOf(gen, ['x', 'y', 'z']), attributes: prng.oneOf(gen, [{ bold: true }, { color: 'red' }, {}]) }
      ]
      text.applyDelta(delta)
      operations.push({ op: 'applyDelta', user, delta })
    }
  }

  const localUpdates = docs.map(doc => Y.encodeStateAsUpdate(doc))
  docs.forEach((doc, docIndex) => {
    localUpdates.forEach((update, updateIndex) => {
      if (docIndex !== updateIndex) {
        Y.applyUpdate(doc, update)
      }
    })
  })

  return {
    name: `seed-${seed}`,
    seed,
    users,
    iterations,
    operations,
    deltas: docs.map(doc => doc.getText('text').toDelta()),
    strings: docs.map(doc => doc.getText('text').toString()),
    updateHexes: docs.map(doc => hex(Y.encodeStateAsUpdate(doc))),
    updateV2Hexes: docs.map(doc => hex(Y.encodeStateAsUpdateV2(doc))),
    stateVectorHexes: docs.map(doc => hex(Y.encodeStateVector(doc)))
  }
}

const makeYTextConvergenceFixtures = () => ({
  source: 'yjs/src/types/YText.js + yjs/tests/y-text.tests.js',
  cases: [5, 30, 40, 42, 43, 44, 70, 90, 300, 500].map(seed => runYTextConvergenceCase(seed, 90, 3))
})

const yxmlAttrs = [
  ['class', 'lead'],
  ['height', '10'],
  ['data-id', 'node'],
  ['count', 42],
  ['enabled', true]
]

const xmlDescriptor = type => {
  if (type instanceof YXmlText) {
    return {
      type: 'YXmlText',
      string: type.toString(),
      delta: type.toDelta(),
      attributes: type.getAttributes()
    }
  }
  if (type instanceof YXmlHook) {
    return {
      type: 'YXmlHook',
      hookName: type.hookName,
      json: type.toJSON(),
      string: String(type)
    }
  }
  if (type instanceof YXmlElement) {
    return {
      type: 'YXmlElement',
      nodeName: type.nodeName,
      attributes: type.getAttributes(),
      string: type.toString(),
      children: type.toArray().map(xmlDescriptor)
    }
  }
  if (type instanceof YXmlFragment) {
    return {
      type: 'YXmlFragment',
      string: type.toString(),
      children: type.toArray().map(xmlDescriptor)
    }
  }
  throw new Error(`Unknown XML type: ${type.constructor.name}`)
}

const captureYXmlScenario = (name, clientID, rootType, rootName, apply) => {
  const doc = new Y.Doc({ guid: `y-php-yxml-${name}` })
  doc.clientID = clientID
  const root = doc.get(rootName, rootType)
  apply(root, doc)
  return {
    name,
    rootName,
    rootType: rootType.name,
    descriptor: xmlDescriptor(root),
    updateHex: hex(Y.encodeStateAsUpdate(doc)),
    updateV2Hex: hex(Y.encodeStateAsUpdateV2(doc)),
    stateVectorHex: hex(Y.encodeStateVector(doc)),
    snapshotHex: hex(Y.encodeSnapshot(Y.snapshot(doc))),
    snapshotV2Hex: hex(Y.encodeSnapshotV2(Y.snapshot(doc)))
  }
}

const makeYXmlFixtures = () => ({
  source: 'yjs/src/types/YXml*.js',
  scenarios: [
    captureYXmlScenario('element-tree', 21, YXmlElement, 'xml', xml => {
      xml.setAttribute('height', '10')
      xml.setAttribute('class', 'root')
      const text = new Y.XmlText('hello')
      text.format(0, 5, { em: {}, strong: { title: 'yes' } })
      const paragraph = new Y.XmlElement('P')
      paragraph.setAttribute('z', 'last')
      paragraph.setAttribute('a', 'first')
      paragraph.insert(0, [new Y.XmlText('nested')])
      const hook = new Y.XmlHook('custom-hook')
      hook.set('payload', { kind: 'widget', n: 1 })
      hook.set('label', 'Hook Label')
      xml.insert(0, [text, paragraph, hook])
    }),
    captureYXmlScenario('fragment-tree', 22, YXmlFragment, 'fragment', fragment => {
      const p1 = new Y.XmlElement('p')
      p1.insert(0, [new Y.XmlText('one')])
      const p2 = new Y.XmlElement('p')
      p2.setAttribute('id', 'two')
      p2.insert(0, [new Y.XmlText('two')])
      fragment.insert(0, [p1, new Y.XmlText(' gap '), p2])
    }),
    captureYXmlScenario('xml-text-formats', 23, YXmlText, 'xmltext', text => {
      text.applyDelta([
        { insert: 'A', attributes: { em: {}, strong: {} } },
        { insert: 'B', attributes: { em: {} } },
        { insert: 'C', attributes: { em: {}, strong: { class: 'heavy' } } }
      ])
      text.setAttribute('role', 'inline')
    })
  ]
})

const runYXmlConvergenceCase = (seed, iterations, users) => {
  const gen = prng.create(seed)
  const docs = Array.from({ length: users }, (_, i) => {
    const doc = new Y.Doc({ guid: `y-php-yxml-${seed}-${i}` })
    doc.clientID = i + 1
    return doc
  })
  const operations = []
  let unique = 0

  for (let i = 0; i < iterations; i++) {
    const user = prng.int32(gen, 0, users - 1)
    const doc = docs[user]
    const xml = doc.get('xml', YXmlElement)
    const op = prng.int32(gen, 0, 6)

    if (op === 0) {
      const [key, value] = prng.oneOf(gen, yxmlAttrs)
      const opValue = typeof value === 'string' ? `${value}-${unique++}` : value
      xml.setAttribute(key, opValue)
      operations.push({ op: 'setAttribute', user, key, value: opValue })
    } else if (op === 1) {
      const [key] = prng.oneOf(gen, yxmlAttrs)
      xml.removeAttribute(key)
      operations.push({ op: 'removeAttribute', user, key })
    } else if (op === 2) {
      const pos = prng.int32(gen, 0, xml.length)
      const value = `${unique++}${prng.word(gen, 1, 4)}`
      xml.insert(pos, [new Y.XmlText(value)])
      operations.push({ op: 'insertText', user, pos, value })
    } else if (op === 3) {
      const pos = prng.int32(gen, 0, xml.length)
      const nodeName = prng.oneOf(gen, ['p', 'span', 'h1'])
      const element = new Y.XmlElement(nodeName)
      element.setAttribute('data-id', `${unique++}`)
      if (prng.bool(gen)) {
        const text = prng.word(gen, 1, 5)
        element.insert(0, [new Y.XmlText(text)])
        xml.insert(pos, [element])
        operations.push({ op: 'insertElement', user, pos, nodeName, attrs: [['data-id', element.getAttribute('data-id')]], text })
      } else {
        xml.insert(pos, [element])
        operations.push({ op: 'insertElement', user, pos, nodeName, attrs: [['data-id', element.getAttribute('data-id')]], text: null })
      }
    } else if (op === 4) {
      const pos = prng.int32(gen, 0, xml.length)
      const hookName = prng.oneOf(gen, ['custom-hook', 'widget-hook'])
      const hook = new Y.XmlHook(hookName)
      const label = `hook-${unique++}`
      hook.set('label', label)
      hook.set('payload', { seed, label })
      xml.insert(pos, [hook])
      operations.push({ op: 'insertHook', user, pos, hookName, entries: [['label', label], ['payload', { seed, label }]] })
    } else if (op === 5 && xml.length > 0) {
      const pos = prng.int32(gen, 0, xml.length - 1)
      xml.delete(pos, 1)
      operations.push({ op: 'delete', user, pos, len: 1 })
    } else {
      const textEntries = xml.toArray()
        .map((child, index) => ({ child, index }))
        .filter(({ child }) => child instanceof Y.XmlText && child.length > 0)
      if (textEntries.length > 0) {
        const { child, index } = prng.oneOf(gen, textEntries)
        const attrs = { [prng.oneOf(gen, ['em', 'strong'])]: {} }
        child.format(0, child.length, attrs)
        operations.push({ op: 'formatTextChild', user, childIndex: index, len: child.length, attrs })
      } else {
        operations.push({ op: 'noop', user })
      }
    }
  }

  const localUpdates = docs.map(doc => Y.encodeStateAsUpdate(doc))
  docs.forEach((doc, docIndex) => {
    localUpdates.forEach((update, updateIndex) => {
      if (docIndex !== updateIndex) {
        Y.applyUpdate(doc, update)
      }
    })
  })

  return {
    name: `seed-${seed}`,
    seed,
    users,
    iterations,
    operations,
    descriptors: docs.map(doc => xmlDescriptor(doc.get('xml', YXmlElement))),
    strings: docs.map(doc => doc.get('xml', YXmlElement).toString()),
    updateHexes: docs.map(doc => hex(Y.encodeStateAsUpdate(doc))),
    updateV2Hexes: docs.map(doc => hex(Y.encodeStateAsUpdateV2(doc))),
    stateVectorHexes: docs.map(doc => hex(Y.encodeStateVector(doc)))
  }
}

const makeYXmlConvergenceFixtures = () => ({
  source: 'yjs/src/types/YXml*.js + yjs/tests/y-xml.tests.js',
  cases: [10, 40, 42, 43, 44, 45, 46, 300, 400, 500].map(seed => runYXmlConvergenceCase(seed, 80, 3))
})

const materializeUpdateCodecInput = input => input.type === 'id'
  ? Y.createID(input.client, input.clock)
  : materialize(input)

const descriptorUpdateCodecValue = value => value instanceof Y.ID
  ? id(value.client, value.clock)
  : descriptor(value)

const encodeUpdateCodecCase = (name, method, input, version = 1) => {
  const value = materializeUpdateCodecInput(input)
  let bytes
  if (method === 'writeID') {
    const encoder = encoding.createEncoder()
    yWriteID(encoder, value)
    bytes = encoding.toUint8Array(encoder)
  } else {
    const encoder = version === 2 ? new Y.UpdateEncoderV2() : new Y.UpdateEncoderV1()
    switch (method) {
      case 'writeLeftID':
        encoder.writeLeftID(value)
        break
      case 'writeRightID':
        encoder.writeRightID(value)
        break
      case 'writeClient':
        encoder.writeClient(value)
        break
      case 'writeInfo':
        encoder.writeInfo(value)
        break
      case 'writeString':
        encoder.writeString(value)
        break
      case 'writeParentInfo':
        encoder.writeParentInfo(value)
        break
      case 'writeTypeRef':
        encoder.writeTypeRef(value)
        break
      case 'writeLen':
        encoder.writeLen(value)
        break
      case 'writeAny':
        encoder.writeAny(value)
        break
      case 'writeBuf':
        encoder.writeBuf(value)
        break
      case 'writeJSON':
        encoder.writeJSON(value)
        break
      case 'writeKey':
        encoder.writeKey(value)
        break
      case 'writeDsClock':
        encoder.writeDsClock(value)
        break
      case 'writeDsLen':
        encoder.writeDsLen(value)
        break
      default:
        throw new Error(`Unknown update codec writer: ${method}`)
    }
    bytes = encoder.toUint8Array()
  }

  const decoder = decoding.createDecoder(bytes)
  let decoded
  if (method === 'writeID') {
    decoded = yReadID(decoder)
  } else {
    const updateDecoder = version === 2 ? new Y.UpdateDecoderV2(decoder) : new Y.UpdateDecoderV1(decoder)
    switch (method) {
      case 'writeLeftID':
        decoded = updateDecoder.readLeftID()
        break
      case 'writeRightID':
        decoded = updateDecoder.readRightID()
        break
      case 'writeClient':
        decoded = updateDecoder.readClient()
        break
      case 'writeInfo':
        decoded = updateDecoder.readInfo()
        break
      case 'writeString':
        decoded = updateDecoder.readString()
        break
      case 'writeParentInfo':
        decoded = updateDecoder.readParentInfo()
        break
      case 'writeTypeRef':
        decoded = updateDecoder.readTypeRef()
        break
      case 'writeLen':
        decoded = updateDecoder.readLen()
        break
      case 'writeAny':
        decoded = updateDecoder.readAny()
        break
      case 'writeBuf':
        decoded = updateDecoder.readBuf()
        break
      case 'writeJSON':
        decoded = updateDecoder.readJSON()
        break
      case 'writeKey':
        decoded = updateDecoder.readKey()
        break
      case 'writeDsClock':
        decoded = updateDecoder.readDsClock()
        break
      case 'writeDsLen':
        decoded = updateDecoder.readDsLen()
        break
      default:
        throw new Error(`Unknown update codec reader: ${method}`)
    }
  }

  if (decoding.hasContent(decoder)) {
    throw new Error(`Fixture case ${name} left unread bytes`)
  }

  return {
    name,
    method,
    input,
    decoded: descriptorUpdateCodecValue(decoded),
    hex: hex(bytes)
  }
}

const makeUpdateCodecFixtures = () => ({
  source: 'yjs/src/utils/ID.js + yjs/src/utils/UpdateEncoder.js + yjs/src/utils/UpdateDecoder.js',
  cases: [
    encodeUpdateCodecCase('writeID small', 'writeID', id(1, 0)),
    encodeUpdateCodecCase('writeID uint32 edge', 'writeID', id(4294967295, 4294967295)),
    encodeUpdateCodecCase('writeLeftID', 'writeLeftID', id(42, 7)),
    encodeUpdateCodecCase('writeRightID', 'writeRightID', id(4294967295, 128)),
    encodeUpdateCodecCase('writeClient uint32 edge', 'writeClient', number(4294967295)),
    encodeUpdateCodecCase('writeInfo zero', 'writeInfo', number(0)),
    encodeUpdateCodecCase('writeInfo max', 'writeInfo', number(255)),
    encodeUpdateCodecCase('writeString unicode slash', 'writeString', string('snow \u2603 / slash')),
    encodeUpdateCodecCase('writeParentInfo true', 'writeParentInfo', bool(true)),
    encodeUpdateCodecCase('writeParentInfo false', 'writeParentInfo', bool(false)),
    encodeUpdateCodecCase('writeTypeRef', 'writeTypeRef', number(9)),
    encodeUpdateCodecCase('writeLen', 'writeLen', number(16384)),
    encodeUpdateCodecCase('writeAny object', 'writeAny', object([
      ['alpha', number(1)],
      ['beta', array([bool(true), string('ok')])]
    ])),
    encodeUpdateCodecCase('writeBuf', 'writeBuf', uint8array([0, 1, 127, 128, 255])),
    encodeUpdateCodecCase('writeJSON object', 'writeJSON', object([
      ['url', string('https://example.com/a/b')],
      ['snow', string('\u2603')],
      ['arr', array([number(1), nil()])]
    ])),
    encodeUpdateCodecCase('writeKey', 'writeKey', string('parent/key')),
    encodeUpdateCodecCase('writeDsClock', 'writeDsClock', number(4294967295)),
    encodeUpdateCodecCase('writeDsLen', 'writeDsLen', number(65536))
  ]
})

const makeUpdateCodecV2Fixtures = () => ({
  source: 'yjs/src/utils/ID.js + yjs/src/utils/UpdateEncoder.js + yjs/src/utils/UpdateDecoder.js',
  cases: [
    encodeUpdateCodecCase('writeID small', 'writeID', id(1, 0), 2),
    encodeUpdateCodecCase('writeID uint32 edge', 'writeID', id(4294967295, 4294967295), 2),
    encodeUpdateCodecCase('writeLeftID', 'writeLeftID', id(42, 7), 2),
    encodeUpdateCodecCase('writeRightID', 'writeRightID', id(4294967295, 128), 2),
    encodeUpdateCodecCase('writeClient uint32 edge', 'writeClient', number(4294967295), 2),
    encodeUpdateCodecCase('writeInfo zero', 'writeInfo', number(0), 2),
    encodeUpdateCodecCase('writeInfo max', 'writeInfo', number(255), 2),
    encodeUpdateCodecCase('writeString unicode slash', 'writeString', string('snow \u2603 / slash'), 2),
    encodeUpdateCodecCase('writeString emoji', 'writeString', string('a\ud83d\ude0ab'), 2),
    encodeUpdateCodecCase('writeParentInfo true', 'writeParentInfo', bool(true), 2),
    encodeUpdateCodecCase('writeParentInfo false', 'writeParentInfo', bool(false), 2),
    encodeUpdateCodecCase('writeTypeRef', 'writeTypeRef', number(9), 2),
    encodeUpdateCodecCase('writeLen', 'writeLen', number(16384), 2),
    encodeUpdateCodecCase('writeAny object', 'writeAny', object([
      ['alpha', number(1)],
      ['beta', array([bool(true), string('ok')])]
    ]), 2),
    encodeUpdateCodecCase('writeBuf', 'writeBuf', uint8array([0, 1, 127, 128, 255]), 2),
    encodeUpdateCodecCase('writeJSON object', 'writeJSON', object([
      ['url', string('https://example.com/a/b')],
      ['snow', string('\u2603')],
      ['arr', array([number(1), nil()])]
    ]), 2),
    encodeUpdateCodecCase('writeKey first', 'writeKey', string('parent/key'), 2),
    encodeUpdateCodecCase('writeDsClock', 'writeDsClock', number(4294967295), 2),
    encodeUpdateCodecCase('writeDsLen', 'writeDsLen', number(65536), 2)
  ]
})

const deleteSetDescriptor = ds => Array.from(ds.clients.entries()).map(([client, deletes]) => ({
  client,
  deletes: deletes.map(item => ({ clock: item.clock, len: item.len }))
}))

const materializeDeleteSet = desc => {
  const ds = new YDeleteSet()
  for (const clientDesc of desc) {
    ds.clients.set(clientDesc.client, clientDesc.deletes.map(item => new YDeleteItem(item.clock, item.len)))
  }
  return ds
}

const encodeDeleteSetCase = (name, input) => {
  const ds = materializeDeleteSet(input)
  const encoder = new Y.UpdateEncoderV1()
  yWriteDeleteSet(encoder, ds)
  const bytes = encoder.toUint8Array()
  const decoder = new Y.UpdateDecoderV1(decoding.createDecoder(bytes))
  const decoded = yReadDeleteSet(decoder)
  if (decoding.hasContent(decoder.restDecoder)) {
    throw new Error(`Delete-set fixture case ${name} left unread bytes`)
  }

  return {
    name,
    input,
    decoded: deleteSetDescriptor(decoded),
    hex: hex(bytes)
  }
}

const makeDeleteSetFixtures = () => ({
  source: 'yjs/src/utils/DeleteSet.js + yjs/src/utils/UpdateEncoder.js + yjs/src/utils/UpdateDecoder.js',
  cases: [
    encodeDeleteSetCase('empty delete set', []),
    encodeDeleteSetCase('single client delete set', [
      { client: 1, deletes: [{ clock: 0, len: 1 }, { clock: 3, len: 2 }, { clock: 10, len: 5 }] }
    ]),
    encodeDeleteSetCase('multi client delete set sorts clients descending', [
      { client: 2, deletes: [{ clock: 0, len: 3 }, { clock: 8, len: 1 }] },
      { client: 4294967295, deletes: [{ clock: 1, len: 4 }] },
      { client: 1, deletes: [{ clock: 7, len: 2 }] }
    ]),
    encodeDeleteSetCase('large clocks and lengths', [
      { client: 123456789, deletes: [{ clock: 4294967290, len: 5 }, { clock: 9007199, len: 65536 }] }
    ])
  ]
})

const encodeDeleteSetV2Case = (name, input) => {
  const ds = materializeDeleteSet(input)
  const encoder = new DSEncoderV2()
  yWriteDeleteSet(encoder, ds)
  const bytes = encoder.toUint8Array()
  const decoder = new DSDecoderV2(decoding.createDecoder(bytes))
  const decoded = yReadDeleteSet(decoder)
  if (decoding.hasContent(decoder.restDecoder)) {
    throw new Error(`Delete-set V2 fixture case ${name} left unread bytes`)
  }

  return {
    name,
    input,
    decoded: deleteSetDescriptor(decoded),
    hex: hex(bytes)
  }
}

const makeDeleteSetV2Fixtures = () => ({
  source: 'yjs/src/utils/DeleteSet.js + yjs/src/utils/UpdateEncoder.js + yjs/src/utils/UpdateDecoder.js',
  cases: [
    encodeDeleteSetV2Case('empty delete set', []),
    encodeDeleteSetV2Case('single client delete set', [
      { client: 1, deletes: [{ clock: 0, len: 1 }, { clock: 3, len: 2 }, { clock: 10, len: 5 }] }
    ]),
    encodeDeleteSetV2Case('multi client delete set sorts clients descending', [
      { client: 2, deletes: [{ clock: 0, len: 3 }, { clock: 8, len: 1 }] },
      { client: 4294967295, deletes: [{ clock: 1, len: 4 }] },
      { client: 1, deletes: [{ clock: 7, len: 2 }] }
    ]),
    encodeDeleteSetV2Case('large clocks and lengths', [
      { client: 123456789, deletes: [{ clock: 9007199, len: 65536 }, { clock: 4294967290, len: 5 }] }
    ])
  ]
})

const makeType = desc => {
  switch (desc.name) {
    case 'YArray':
      return new YArray()
    case 'YMap':
      return new YMap()
    case 'YText':
      return new YText()
    case 'YXmlElement':
      return new YXmlElement(desc.nodeName)
    case 'YXmlFragment':
      return new YXmlFragment()
    case 'YXmlHook':
      return new YXmlHook(desc.hookName)
    case 'YXmlText':
      return new YXmlText()
  }
  throw new Error(`Unknown type descriptor: ${desc.name}`)
}

const typeDescriptor = type => {
  if (type instanceof YXmlElement) {
    return { name: 'YXmlElement', nodeName: type.nodeName }
  }
  if (type instanceof YXmlHook) {
    return { name: 'YXmlHook', hookName: type.hookName }
  }
  if (type instanceof YXmlText) {
    return { name: 'YXmlText' }
  }
  if (type instanceof YXmlFragment) {
    return { name: 'YXmlFragment' }
  }
  if (type instanceof YArray) {
    return { name: 'YArray' }
  }
  if (type instanceof YMap) {
    return { name: 'YMap' }
  }
  if (type instanceof YText) {
    return { name: 'YText' }
  }
  throw new Error(`Unable to describe type: ${type.constructor.name}`)
}

const makeContent = desc => {
  switch (desc.kind) {
    case 'ContentString':
      return new ContentString(desc.str)
    case 'ContentAny':
      return new ContentAny(desc.arr.map(materialize))
    case 'ContentJSON':
      return new ContentJSON(desc.arr.map(materialize))
    case 'ContentBinary':
      return new ContentBinary(materialize(desc.content))
    case 'ContentEmbed':
      return new ContentEmbed(materialize(desc.embed))
    case 'ContentFormat':
      return new ContentFormat(desc.key, materialize(desc.value))
    case 'ContentDeleted':
      return new ContentDeleted(desc.len)
    case 'ContentType':
      return new ContentType(makeType(desc.type))
    case 'ContentDoc': {
      const opts = {}
      for (const [key, value] of desc.opts ?? []) {
        opts[key] = materialize(value)
      }
      return new ContentDoc(new Y.Doc({ guid: desc.guid, ...opts }))
    }
  }
  throw new Error(`Unknown content kind: ${desc.kind}`)
}

const readContent = (kind, decoder) => {
  switch (kind) {
    case 'ContentString':
      return readContentString(decoder)
    case 'ContentAny':
      return readContentAny(decoder)
    case 'ContentJSON':
      return readContentJSON(decoder)
    case 'ContentBinary':
      return readContentBinary(decoder)
    case 'ContentEmbed':
      return readContentEmbed(decoder)
    case 'ContentFormat':
      return readContentFormat(decoder)
    case 'ContentDeleted':
      return readContentDeleted(decoder)
    case 'ContentType':
      return readContentType(decoder)
    case 'ContentDoc':
      return readContentDoc(decoder)
  }
  throw new Error(`Unknown content reader: ${kind}`)
}

const contentDescriptor = content => {
  if (content instanceof ContentString) {
    return { kind: 'ContentString', str: content.str }
  }
  if (content instanceof ContentAny) {
    return { kind: 'ContentAny', arr: content.arr.map(descriptor) }
  }
  if (content instanceof ContentJSON) {
    return { kind: 'ContentJSON', arr: content.arr.map(descriptor) }
  }
  if (content instanceof ContentBinary) {
    return { kind: 'ContentBinary', content: descriptor(content.content) }
  }
  if (content instanceof ContentEmbed) {
    return { kind: 'ContentEmbed', embed: descriptor(content.embed) }
  }
  if (content instanceof ContentFormat) {
    return { kind: 'ContentFormat', key: content.key, value: descriptor(content.value) }
  }
  if (content instanceof ContentDeleted) {
    return { kind: 'ContentDeleted', len: content.len }
  }
  if (content instanceof ContentType) {
    return { kind: 'ContentType', type: typeDescriptor(content.type) }
  }
  if (content instanceof ContentDoc) {
    return {
      kind: 'ContentDoc',
      guid: content.doc.guid,
      opts: Object.keys(content.opts).map(key => [key, descriptor(content.opts[key])])
    }
  }
  throw new Error(`Unable to describe content: ${content.constructor.name}`)
}

const contentCase = (name, input, offset = 0) => {
  const content = makeContent(input)
  const encoder = new Y.UpdateEncoderV1()
  content.write(encoder, offset)
  const bytes = encoder.toUint8Array()
  const decoder = new Y.UpdateDecoderV1(decoding.createDecoder(bytes))
  const decoded = readContent(input.kind, decoder)
  if (decoding.hasContent(decoder.restDecoder)) {
    throw new Error(`Content fixture case ${name} left unread bytes`)
  }

  return {
    name,
    input,
    offset,
    decoded: contentDescriptor(decoded),
    hex: hex(bytes),
    ref: content.getRef(),
    length: content.getLength()
  }
}

const structCase = (name, struct, offset = 0) => {
  const encoder = new Y.UpdateEncoderV1()
  struct.write(encoder, offset)
  return {
    name,
    kind: struct.constructor.name,
    id: id(struct.id.client, struct.id.clock),
    length: struct.length,
    offset,
    hex: hex(encoder.toUint8Array())
  }
}

const makeStructContentFixtures = () => ({
  source: 'yjs/src/structs/{AbstractStruct,GC,Skip,Content*}.js',
  contentCases: [
    contentCase('ContentString ascii', { kind: 'ContentString', str: 'hello' }),
    contentCase('ContentString utf16 offset', { kind: 'ContentString', str: 'a\ud83d\ude0ab' }, 1),
    contentCase('ContentAny writeAny matrix', {
      kind: 'ContentAny',
      arr: [
        undef(),
        nil(),
        bool(true),
        bool(false),
        string('any-string'),
        string('snow \u2603'),
        specialNumber('-0'),
        number(42),
        number(-42),
        number(2147483647),
        number(2147483648),
        number(2147483649),
        number(1.5),
        number(0.1),
        specialNumber('NaN'),
        bigint('-9223372036854775808'),
        uint8array([5, 6, 7]),
        array([number(1), string('two'), nil(), undef(), uint8array([9])]),
        object([
          ['alpha', number(1)],
          ['beta', array([bool(true), string('ok')])],
          ['gamma', object([['nested', nil()]])]
        ]),
        object([])
      ]
    }),
    contentCase('ContentAny offset', { kind: 'ContentAny', arr: [number(1), string('two'), bool(false)] }, 1),
    contentCase('ContentJSON values', {
      kind: 'ContentJSON',
      arr: [
        undef(),
        nil(),
        bool(true),
        number(1),
        number(0.1),
        specialNumber('NaN'),
        object([
          ['url', string('https://example.com/a/b')],
          ['snow', string('\u2603')],
          ['arr', array([number(1), nil(), undef()])],
          ['omit', undef()]
        ])
      ]
    }),
    contentCase('ContentBinary bytes', { kind: 'ContentBinary', content: uint8array([0, 1, 127, 128, 255]) }),
    contentCase('ContentEmbed object', {
      kind: 'ContentEmbed',
      embed: object([
        ['src', string('https://example.com/a/b')],
        ['dims', object([['w', number(640)], ['h', number(480)]])]
      ])
    }),
    contentCase('ContentFormat key value', { kind: 'ContentFormat', key: 'bold/style', value: object([['enabled', bool(true)]]) }),
    contentCase('ContentDeleted len', { kind: 'ContentDeleted', len: 16384 }),
    contentCase('ContentType YArray', { kind: 'ContentType', type: { name: 'YArray' } }),
    contentCase('ContentType YMap', { kind: 'ContentType', type: { name: 'YMap' } }),
    contentCase('ContentType YText', { kind: 'ContentType', type: { name: 'YText' } }),
    contentCase('ContentType YXmlElement', { kind: 'ContentType', type: { name: 'YXmlElement', nodeName: 'paragraph' } }),
    contentCase('ContentType YXmlFragment', { kind: 'ContentType', type: { name: 'YXmlFragment' } }),
    contentCase('ContentType YXmlHook', { kind: 'ContentType', type: { name: 'YXmlHook', hookName: 'hook-name' } }),
    contentCase('ContentType YXmlText', { kind: 'ContentType', type: { name: 'YXmlText' } }),
    contentCase('ContentDoc default opts', { kind: 'ContentDoc', guid: 'doc-guid-default', opts: [] }),
    contentCase('ContentDoc encoded opts', {
      kind: 'ContentDoc',
      guid: 'doc-guid-opts',
      opts: [
        ['gc', bool(false)],
        ['autoLoad', bool(true)],
        ['meta', object([['role', string('child')]])]
      ]
    })
  ],
  structCases: [
    structCase('GC write full', new GC(Y.createID(42, 7), 5)),
    structCase('GC write offset', new GC(Y.createID(42, 7), 5), 2),
    structCase('Skip write full', new Skip(Y.createID(7, 9), 16384)),
    structCase('Skip write offset', new Skip(Y.createID(7, 9), 16384), 3)
  ]
})

fs.mkdirSync(fixturesDir, { recursive: true })
fs.writeFileSync(
  path.join(fixturesDir, 'encoding-primitives.json'),
  `${JSON.stringify({ cases }, null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'prng.json'),
  `${JSON.stringify(makePrngFixture(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'yjs-scenarios.json'),
  `${JSON.stringify(makeYjsScenarioFixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'update-utilities-v1.json'),
  `${JSON.stringify(makeUpdateUtilityFixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'update-utilities-v2.json'),
  `${JSON.stringify(makeUpdateUtilityV2Fixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'yarray-convergence.json'),
  `${JSON.stringify(makeYArrayConvergenceFixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'ymap-convergence.json'),
  `${JSON.stringify(makeYMapConvergenceFixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'gc-resolution.json'),
  `${JSON.stringify(makeGcResolutionFixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'ytext-scenarios.json'),
  `${JSON.stringify(makeYTextFixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'ytext-convergence.json'),
  `${JSON.stringify(makeYTextConvergenceFixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'yxml-scenarios.json'),
  `${JSON.stringify(makeYXmlFixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'yxml-convergence.json'),
  `${JSON.stringify(makeYXmlConvergenceFixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'update-codecs-v1.json'),
  `${JSON.stringify(makeUpdateCodecFixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'update-codecs-v2.json'),
  `${JSON.stringify(makeUpdateCodecV2Fixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'delete-set-v1.json'),
  `${JSON.stringify(makeDeleteSetFixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'delete-set-v2.json'),
  `${JSON.stringify(makeDeleteSetV2Fixtures(), null, 2)}\n`
)
fs.writeFileSync(
  path.join(fixturesDir, 'struct-content.json'),
  `${JSON.stringify(makeStructContentFixtures(), null, 2)}\n`
)
